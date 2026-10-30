<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ActivityController extends Controller
{
    private const STATUSES = ['DRAFT', 'AKTIF', 'SELESAI', 'DIBATALKAN'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'tahun' => ['nullable', 'digits:4'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $query = $this->scopedQuery($request->user())->with('targets');

        if (!empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(fn (Builder $builder) => $builder
                ->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('territory', 'like', "%{$term}%"));
        }
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['tahun'])) $query->where('year', $filters['tahun']);

        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);
        $total = (clone $query)->count();
        $items = $query->orderByDesc('year')->orderBy('name')->offset($offset)->limit($limit)->get();

        return response()->json([
            'items' => $items->map(fn (Activity $activity) => $this->resource($activity))->values(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function show(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($this->scopedQuery($request->user())->whereKey($activity->id)->exists(), 403, 'Kegiatan di luar lingkup akun.');
        return response()->json($this->resource($activity->load('targets')));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(['ADMIN_BPS', 'PJK', 'OPERATOR']), 403);
        $data = $this->validatedData($request);

        return DB::transaction(function () use ($request, $data) {
            $activity = Activity::create($this->attributes($data, $request->user(), 'DRAFT'));
            $this->replaceTargets($activity, $data['targetPekerjaan'] ?? []);
            app(AuditLogger::class)->record($request, 'ACTIVITY_CREATED', 'Activity', $activity->id, null, [
                'code' => $activity->code,
                'name' => $activity->name,
                'year' => $activity->year,
                'status' => $activity->status,
            ]);
            return response()->json($this->resource($activity->load('targets')), 201);
        });
    }

    public function update(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($request->user()->hasRole(['ADMIN_BPS', 'PJK', 'OPERATOR']), 403);
        abort_unless($this->scopedQuery($request->user())->whereKey($activity->id)->exists(), 403, 'Kegiatan di luar lingkup akun.');
        if ($activity->status !== 'DRAFT') {
            return response()->json(['message' => 'Hanya kegiatan DRAFT yang dapat diubah.'], 409);
        }
        if ($activity->assignments()->exists()) {
            return response()->json(['message' => 'Kegiatan yang sudah memiliki penugasan tidak dapat diubah.'], 409);
        }

        $data = $this->validatedData($request, $activity);
        $before = $activity->only(['code', 'name', 'year', 'period', 'responsible_user_id', 'responsible_name', 'activity_type', 'description', 'territory', 'target_volume', 'unit', 'starts_on', 'ends_on', 'administrative_requirements', 'partner_need', 'status']);
        return DB::transaction(function () use ($request, $activity, $data, $before) {
            $activity->fill($this->attributes($data, $request->user(), $activity->status, $activity));
            $activity->save();
            $this->replaceTargets($activity, $data['targetPekerjaan'] ?? []);
            app(AuditLogger::class)->record($request, 'ACTIVITY_UPDATED', 'Activity', $activity->id, $before, $activity->fresh()->only(array_keys($before)));
            return response()->json($this->resource($activity->fresh()->load('targets')));
        });
    }

    public function activate(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($request->user()->hasRole(['ADMIN_BPS', 'PJK']), 403);
        abort_unless($this->scopedQuery($request->user())->whereKey($activity->id)->exists(), 403, 'Kegiatan di luar lingkup akun.');
        if ($activity->status !== 'DRAFT') {
            return response()->json(['message' => 'Hanya kegiatan DRAFT yang dapat diaktifkan.'], 409);
        }

        $missing = [];
        if (!$activity->responsible_name && !$activity->responsible_user_id) $missing[] = 'penanggung jawab';
        if (!$activity->starts_on || !$activity->ends_on) $missing[] = 'jadwal kegiatan';
        if ($activity->partner_need < 1) $missing[] = 'kebutuhan mitra';
        if (!$activity->targets()->exists()) $missing[] = 'target pekerjaan';
        if ($activity->starts_on && $activity->ends_on && $activity->ends_on->lt($activity->starts_on)) $missing[] = 'rentang tanggal valid';
        if ($missing !== []) return response()->json(['message' => 'Belum lengkap: '.implode(', ', $missing).'.'], 409);

        $previousStatus = $activity->status;
        $activity->forceFill(['status' => 'AKTIF', 'updated_by' => $request->user()->id])->save();
        app(AuditLogger::class)->record($request, 'ACTIVITY_ACTIVATED', 'Activity', $activity->id,
            ['status' => $previousStatus], ['status' => $activity->status]);
        return response()->json($this->resource($activity->fresh()->load('targets')));
    }

    private function scopedQuery(User $user): Builder
    {
        $query = Activity::query();
        if ($user->hasRole('ADMIN_BPS')) return $query;
        if ($user->hasRole('PJK')) {
            return $query->where(function (Builder $builder) use ($user) {
                $builder->where('responsible_user_id', $user->id)
                    ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('coordinator_id', $user->id));
            });
        }
        if ($user->hasRole('OPERATOR')) {
            return $query->where(fn (Builder $builder) => $builder
                ->where('created_by', $user->id)
                ->orWhere('responsible_user_id', $user->id));
        }
        if ($user->hasRole('PETUGAS_MITRA') && $user->partner_id) {
            return $query->whereHas('assignments.members', fn (Builder $member) => $member->where('partner_id', $user->partner_id));
        }
        abort(403, 'Tidak memiliki lingkup akses kegiatan.');
    }

    private function validatedData(Request $request, ?Activity $activity = null): array
    {
        $id = $activity?->id;
        return $request->validate([
            'kode' => ['nullable', 'string', 'max:50', Rule::unique('activities', 'code')->ignore($id)],
            'nama' => ['required', 'string', 'max:200'],
            'tahunAnggaran' => ['required', 'digits:4'],
            'periode' => ['nullable', 'string', 'max:100'],
            'penanggungJawabId' => ['nullable', 'integer', 'exists:users,id'],
            'penanggungJawab' => ['nullable', 'string', 'max:180'],
            'jenisKegiatan' => ['nullable', 'string', 'max:100'],
            'uraian' => ['nullable', 'string', 'max:5000'],
            'wilayah' => ['nullable', 'string', 'max:200'],
            'targetVolume' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'satuan' => ['nullable', 'string', 'max:40'],
            'tglMulai' => ['nullable', 'date_format:Y-m-d'],
            'tglSelesai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tglMulai'],
            'persyaratanAdministrasi' => ['nullable', 'array', 'max:30'],
            'persyaratanAdministrasi.*' => ['string', 'max:200'],
            'kebutuhanMitra' => ['required', 'integer', 'min:0', 'max:10000'],
            'targetPekerjaan' => ['required', 'array', 'min:1', 'max:50'],
            'targetPekerjaan.*.uraian' => ['required', 'string', 'max:500'],
            'targetPekerjaan.*.target' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'targetPekerjaan.*.satuan' => ['nullable', 'string', 'max:40'],
            'targetPekerjaan.*.wilayah' => ['nullable', 'string', 'max:150'],
            'targetPekerjaan.*.jadwalMulai' => ['nullable', 'date_format:Y-m-d'],
            'targetPekerjaan.*.jadwalSelesai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:targetPekerjaan.*.jadwalMulai'],
        ]);
    }

    private function attributes(array $data, User $user, string $status, ?Activity $existing = null): array
    {
        $year = (int) $data['tahunAnggaran'];
        $code = trim((string) ($data['kode'] ?? ''));
        if ($code === '') {
            do {
                $code = 'K-'.$year.'-'.Str::upper(Str::random(6));
            } while (Activity::where('code', $code)->exists());
        }

        return [
            'code' => $code,
            'name' => trim($data['nama']),
            'year' => $year,
            'period' => $data['periode'] ?? null,
            'responsible_user_id' => $data['penanggungJawabId'] ?? null,
            'responsible_name' => $data['penanggungJawab'] ?? null,
            'activity_type' => $data['jenisKegiatan'] ?? null,
            'description' => $data['uraian'] ?? null,
            'territory' => $data['wilayah'] ?? null,
            'target_volume' => $data['targetVolume'],
            'unit' => $data['satuan'] ?? null,
            'starts_on' => $data['tglMulai'] ?? null,
            'ends_on' => $data['tglSelesai'] ?? null,
            'administrative_requirements' => $data['persyaratanAdministrasi'] ?? [],
            'partner_need' => $data['kebutuhanMitra'],
            'status' => $status,
            'is_demo' => false,
            'created_by' => $existing?->created_by ?? $user->id,
            'updated_by' => $user->id,
        ];
    }

    private function replaceTargets(Activity $activity, array $targets): void
    {
        $activity->targets()->delete();
        foreach (array_values($targets) as $index => $target) {
            $activity->targets()->create([
                'description' => trim($target['uraian']),
                'target_volume' => $target['target'],
                'unit' => $target['satuan'] ?? null,
                'territory' => $target['wilayah'] ?? null,
                'starts_on' => $target['jadwalMulai'] ?? null,
                'ends_on' => $target['jadwalSelesai'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    private function resource(Activity $activity): array
    {
        return [
            'id' => (string) $activity->id,
            'kode' => $activity->code,
            'nama' => $activity->name,
            'tahunAnggaran' => (string) $activity->year,
            'periode' => $activity->period ?? '',
            'penanggungJawab' => $activity->responsible_name ?? '',
            'penanggungJawabId' => $activity->responsible_user_id ? (string) $activity->responsible_user_id : '',
            'jenisKegiatan' => $activity->activity_type ?? '',
            'uraian' => $activity->description ?? '',
            'wilayah' => $activity->territory ?? '',
            'targetVolume' => (float) $activity->target_volume,
            'satuan' => $activity->unit ?? '',
            'tglMulai' => $activity->starts_on?->format('Y-m-d') ?? '',
            'tglSelesai' => $activity->ends_on?->format('Y-m-d') ?? '',
            'persyaratanAdministrasi' => $activity->administrative_requirements ?? [],
            'kebutuhanMitra' => $activity->partner_need,
            'targetPekerjaan' => $activity->targets->map(fn ($target) => [
                'uraian' => $target->description,
                'target' => (float) $target->target_volume,
                'satuan' => $target->unit ?? '',
                'wilayah' => $target->territory ?? '',
                'jadwalMulai' => $target->starts_on?->format('Y-m-d') ?? '',
                'jadwalSelesai' => $target->ends_on?->format('Y-m-d') ?? '',
            ])->values(),
            'status' => $activity->status,
            'demo' => (bool) $activity->is_demo,
            'createdAt' => $activity->created_at?->getTimestampMs() ?? 0,
            'updatedAt' => $activity->updated_at?->getTimestampMs() ?? 0,
        ];
    }
}
