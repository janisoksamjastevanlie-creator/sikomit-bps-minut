<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\AssignmentMember;
use App\Models\Partner;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AssignmentController extends Controller
{
    private const CONTRACT_CATEGORIES = [
        'PENDATAAN_LAPANGAN', 'PENGOLAHAN', 'PENDATAAN_DAN_PENGOLAHAN',
    ];

    private const TERMINAL_STATUSES = ['SELESAI', 'DITUTUP', 'DIBATALKAN'];

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(['ADMIN_BPS', 'PJK', 'OPERATOR']), 403);
        $activities = $this->manageableActivities($user)->where('status', 'AKTIF')->orderBy('name')->get();
        $partners = Partner::query()
            ->where('is_active', true)
            ->where('is_demo', false)
            ->where('verification_status', 'TERVERIFIKASI')
            ->orderBy('full_name')
            ->get(['id', 'partner_code', 'sobat_id', 'full_name']);

        return response()->json([
            'kegiatan' => $activities->map(fn (Activity $activity) => [
                'id' => (string) $activity->id,
                'kode' => $activity->code,
                'nama' => $activity->name,
                'tahunAnggaran' => (string) $activity->year,
                'status' => $activity->status,
                'targetVolume' => (float) $activity->target_volume,
                'satuan' => $activity->unit ?? '',
                'wilayah' => $activity->territory ?? '',
                'tglMulai' => $activity->starts_on?->format('Y-m-d') ?? '',
                'tglSelesai' => $activity->ends_on?->format('Y-m-d') ?? '',
                'targetPekerjaan' => $activity->targets()->orderBy('sort_order')->get()->map(fn ($target) => [
                    'uraian' => $target->description,
                    'target' => (float) $target->target_volume,
                    'satuan' => $target->unit ?? '',
                    'wilayah' => $target->territory ?? '',
                    'jadwalMulai' => $target->starts_on?->format('Y-m-d') ?? '',
                    'jadwalSelesai' => $target->ends_on?->format('Y-m-d') ?? '',
                ])->values(),
            ])->values(),
            'mitra' => $partners->map(fn (Partner $partner) => [
                'id' => (string) $partner->id,
                'nama' => $partner->full_name,
                'sobatId' => $partner->sobat_id ?? '',
                'kode' => $partner->partner_code ?? '',
            ])->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['DITUGASKAN', 'DALAM_PELAKSANAAN', 'SELESAI', 'DITUTUP', 'DIBATALKAN'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $query = $this->visibleAssignments($request->user())->with(['activity', 'members.partner', 'members.workItems']);

        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function (Builder $builder) use ($term) {
                $builder->where('description', 'like', "%{$term}%")
                    ->orWhereHas('activity', fn (Builder $activity) => $activity
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('code', 'like', "%{$term}%"))
                    ->orWhereHas('members.partner', fn (Builder $partner) => $partner->where('full_name', 'like', "%{$term}%"));
            });
        }

        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);
        $total = (clone $query)->count();
        $items = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return response()->json([
            'items' => $items->map(fn (Assignment $assignment) => $this->resource($assignment))->values(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        abort_unless($this->visibleAssignments($request->user())->whereKey($assignment->id)->exists(), 403, 'Penugasan di luar lingkup akun.');
        return response()->json($this->resource($assignment->load(['activity', 'members.partner', 'members.workItems'])));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(['ADMIN_BPS', 'PJK', 'OPERATOR']), 403);

        $data = $request->validate([
            'kegiatanId' => ['required', 'integer', 'exists:activities,id'],
            'mitraId' => ['nullable', 'integer', 'exists:partners,id', 'required_without:mitraIds'],
            'mitraIds' => ['nullable', 'array', 'min:1', 'max:50', 'required_without:mitraId'],
            'mitraIds.*' => ['integer', 'distinct', 'exists:partners,id'],
            'contractCategory' => ['required', Rule::in(self::CONTRACT_CATEGORIES)],
            'uraian' => ['required', 'string', 'max:1000'],
            'wilayah' => ['nullable', 'string', 'max:200'],
            'jadwalMulai' => ['required', 'date_format:Y-m-d'],
            'jadwalSelesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:jadwalMulai'],
            'targetPekerjaan' => ['required', 'array', 'min:1', 'max:50'],
            'targetPekerjaan.*.uraian' => ['required', 'string', 'max:500'],
            'targetPekerjaan.*.target' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'targetPekerjaan.*.satuan' => ['required', 'string', 'max:40'],
            'targetPekerjaan.*.wilayah' => ['nullable', 'string', 'max:150'],
            'targetPekerjaan.*.jadwalMulai' => ['nullable', 'date_format:Y-m-d'],
            'targetPekerjaan.*.jadwalSelesai' => ['nullable', 'date_format:Y-m-d'],
            'targetPekerjaan.*.workCategory' => ['nullable', Rule::in(['PENDATAAN_LAPANGAN', 'PENGOLAHAN'])],
        ]);

        $activity = $this->manageableActivities($user)->whereKey($data['kegiatanId'])->firstOrFail();
        if ($activity->status !== 'AKTIF' || $activity->is_demo) {
            return response()->json(['message' => 'Penugasan hanya dapat menggunakan kegiatan aktif non-demonstrasi.'], 409);
        }
        $startsOn = Carbon::createFromFormat('Y-m-d', $data['jadwalMulai'])->startOfDay();
        $endsOn = Carbon::createFromFormat('Y-m-d', $data['jadwalSelesai'])->startOfDay();
        if (($activity->starts_on && $startsOn->lt($activity->starts_on)) ||
            ($activity->ends_on && $endsOn->gt($activity->ends_on))) {
            return response()->json(['message' => 'Jadwal penugasan harus berada dalam periode kegiatan.'], 422);
        }

        $partnerIds = array_values(array_unique(array_map('intval', $data['mitraIds'] ?? [$data['mitraId']])));
        $targets = $data['targetPekerjaan'];
        if ($data['contractCategory'] === 'PENDATAAN_DAN_PENGOLAHAN') {
            $workCategories = collect($targets)->pluck('workCategory')->filter()->unique()->all();
            if (!in_array('PENDATAAN_LAPANGAN', $workCategories, true) || !in_array('PENGOLAHAN', $workCategories, true)) {
                return response()->json(['message' => 'Penugasan gabungan harus merinci target pendataan dan pengolahan secara terpisah.'], 422);
            }
        }

        return DB::transaction(function () use ($request, $data, $user, $activity, $partnerIds, $startsOn, $endsOn, $targets) {
            $partners = Partner::query()->whereIn('id', $partnerIds)->orderBy('id')->lockForUpdate()->get();
            if ($partners->count() !== count($partnerIds) || $partners->contains(fn (Partner $partner) =>
                !$partner->is_active || $partner->is_demo || $partner->verification_status !== 'TERVERIFIKASI')) {
                return response()->json(['message' => 'Semua petugas harus aktif, terverifikasi, dan bukan data demonstrasi.'], 409);
            }

            foreach ($partners as $partner) {
                $conflict = AssignmentMember::query()
                    ->where('partner_id', $partner->id)
                    ->whereHas('assignment', fn (Builder $query) => $query
                        ->whereNotIn('status', ['SELESAI', 'DITUTUP', 'DIBATALKAN'])
                        ->whereDate('starts_on', '<=', $endsOn)
                        ->whereDate('ends_on', '>=', $startsOn))
                    ->exists();
                if ($conflict) {
                    return response()->json(['message' => "Jadwal bertabrakan dengan penugasan aktif mitra {$partner->full_name}."], 409);
                }
            }

            $assignment = Assignment::create([
                'activity_id' => $activity->id,
                'description' => trim($data['uraian']),
                'territory' => $data['wilayah'] ?? $activity->territory,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'status' => 'DITUGASKAN',
                'coordinator_id' => $user->hasRole('PJK') ? $user->id : null,
                'is_demo' => false,
                'created_by' => $user->id,
            ]);

            foreach ($partners as $partner) {
                $member = $assignment->members()->create([
                    'partner_id' => $partner->id,
                    'status' => 'DITUGASKAN',
                    'contract_category' => $data['contractCategory'],
                    'assigned_at' => now(),
                    'created_by' => $user->id,
                ]);
                foreach (array_values($targets) as $index => $target) {
                    $member->workItems()->create([
                        'activity_target_id' => null,
                        'description' => trim($target['uraian']),
                        'target_volume' => $target['target'],
                        'unit' => $target['satuan'],
                        'territory' => $target['wilayah'] ?? $assignment->territory,
                        'starts_on' => $target['jadwalMulai'] ?? $assignment->starts_on,
                        'ends_on' => $target['jadwalSelesai'] ?? $assignment->ends_on,
                        'work_category' => $target['workCategory'] ?? $data['contractCategory'],
                    ]);
                }
            }

            app(AuditLogger::class)->record($request, 'ASSIGNMENT_CREATED', 'Assignment', $assignment->id, null, [
                'activity_id' => $activity->id,
                'partner_ids' => $partnerIds,
                'contract_category' => $data['contractCategory'],
                'starts_on' => $assignment->starts_on->format('Y-m-d'),
                'ends_on' => $assignment->ends_on->format('Y-m-d'),
                'status' => $assignment->status,
            ]);

            return response()->json($this->resource($assignment->load(['activity', 'members.partner', 'members.workItems'])), 201);
        });
    }

    private function manageableActivities(User $user): Builder
    {
        $query = Activity::query();
        if ($user->hasRole('ADMIN_BPS')) return $query;
        if ($user->hasRole('PJK')) {
            return $query->where(fn (Builder $builder) => $builder
                ->where('responsible_user_id', $user->id)
                ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('coordinator_id', $user->id)));
        }
        if ($user->hasRole('OPERATOR')) return $query->where('created_by', $user->id);
        abort(403, 'Tidak memiliki lingkup pengelolaan penugasan.');
    }

    private function visibleAssignments(User $user): Builder
    {
        $query = Assignment::query();
        if ($user->hasRole('ADMIN_BPS')) return $query;
        if ($user->hasRole('PJK')) {
            return $query->where(fn (Builder $builder) => $builder
                ->where('coordinator_id', $user->id)
                ->orWhereHas('activity', fn (Builder $activity) => $activity->where('responsible_user_id', $user->id)));
        }
        if ($user->hasRole('OPERATOR')) return $query->where('created_by', $user->id);
        if ($user->hasRole('PETUGAS_MITRA') && $user->partner_id) {
            return $query->whereHas('members', fn (Builder $member) => $member->where('partner_id', $user->partner_id));
        }
        abort(403, 'Tidak memiliki lingkup akses penugasan.');
    }

    private function resource(Assignment $assignment): array
    {
        $members = $assignment->members->map(fn ($member) => [
            'id' => (string) $member->partner_id,
            'assignmentMemberId' => (string) $member->id,
            'nama' => $member->partner?->full_name ?? '',
            'sobatId' => $member->partner?->sobat_id ?? '',
            'contractCategory' => $member->contract_category,
            'status' => $member->status,
            'targetPekerjaan' => $member->workItems->map(fn ($item) => [
                'assignmentWorkItemId' => (string) $item->id,
                'uraian' => $item->description,
                'target' => (float) $item->target_volume,
                'satuan' => $item->unit ?? '',
                'wilayah' => $item->territory ?? '',
                'jadwalMulai' => $item->starts_on?->format('Y-m-d') ?? '',
                'jadwalSelesai' => $item->ends_on?->format('Y-m-d') ?? '',
                'workCategory' => $item->work_category,
            ])->values(),
        ])->values();
        $first = $members->first();

        return [
            'id' => (string) $assignment->id,
            'kegiatanId' => (string) $assignment->activity_id,
            'kegiatanKode' => $assignment->activity?->code ?? '',
            'kegiatanNama' => $assignment->activity?->name ?? '',
            'mitraId' => $first['id'] ?? '',
            'mitraNama' => $members->count() > 1 ? "{$members->count()} petugas" : ($first['nama'] ?? ''),
            'contractCategory' => $first['contractCategory'] ?? '',
            'members' => $members,
            'uraian' => $assignment->description,
            'targetPekerjaan' => $first['targetPekerjaan'] ?? [],
            'volume' => (float) collect($first['targetPekerjaan'] ?? [])->sum('target'),
            'satuan' => $first['targetPekerjaan'][0]['satuan'] ?? '',
            'wilayah' => $assignment->territory ?? '',
            'jadwalMulai' => $assignment->starts_on?->format('Y-m-d') ?? '',
            'jadwalSelesai' => $assignment->ends_on?->format('Y-m-d') ?? '',
            'status' => $assignment->status,
            'demo' => (bool) $assignment->is_demo,
            'createdAt' => $assignment->created_at?->getTimestampMs() ?? 0,
        ];
    }
}
