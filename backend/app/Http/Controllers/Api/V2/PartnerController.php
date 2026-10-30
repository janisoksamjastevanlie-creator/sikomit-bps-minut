<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerVerificationHistory;
use App\Models\AssignmentMember;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnerController extends Controller
{
    private const VERIFICATION_STATUSES = [
        'DRAFT', 'MENUNGGU_VERIFIKASI', 'PERLU_PERBAIKAN', 'TERVERIFIKASI', 'TIDAK_MEMENUHI',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::VERIFICATION_STATUSES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $query = Partner::query();

        if ($user->hasRole(['ADMIN_BPS', 'ADMIN_MITRA', 'OPERATOR'])) {
            // Administrative scope, filtered below.
        } elseif ($user->hasRole('PEMERIKSA')) {
            $query->where('verification_status', 'MENUNGGU_VERIFIKASI');
        } elseif ($user->hasRole('PJK')) {
            $query->whereIn('id', AssignmentMember::query()->whereHas('assignment', fn ($assignment) => $assignment
                ->where('coordinator_id', $user->id)
                ->orWhereHas('activity', fn ($activity) => $activity->where('responsible_user_id', $user->id)))
                ->select('partner_id'));
        } elseif ($user->hasRole('PETUGAS_MITRA') && $user->partner_id) {
            $query->whereKey($user->partner_id);
        } else {
            return response()->json(['message' => 'Tidak memiliki lingkup akses data mitra.'], 403);
        }

        if (!empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function ($builder) use ($term) {
                $builder->where('full_name', 'like', "%{$term}%")
                    ->orWhere('partner_code', 'like', "%{$term}%")
                    ->orWhere('sobat_id', 'like', "%{$term}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('verification_status', $filters['status']);
        }

        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);
        $total = (clone $query)->count();
        $partners = $query->orderBy('full_name')->offset($offset)->limit($limit)->get();

        return response()->json([
            'items' => $partners->map(fn (Partner $partner) => $this->summary($partner))->values(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function show(Request $request, Partner $partner): JsonResponse
    {
        if (!$this->canRead($request->user(), $partner)) {
            return response()->json(['message' => 'Tidak berwenang melihat profil mitra ini.'], 403);
        }

        return response()->json($this->detail($partner, $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:180'],
            'sobatId' => ['nullable', 'string', 'max:60', 'unique:partners,sobat_id'],
            'partnerCode' => ['nullable', 'string', 'max:50', 'unique:partners,partner_code'],
            'jenis' => ['required', Rule::in(['PERORANGAN', 'LEMBAGA', 'PERUSAHAAN'])],
            'wa' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'kecamatan' => ['nullable', 'string', 'max:100'],
            'desa' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
        ]);

        $partner = Partner::create([
            'partner_code' => $data['partnerCode'] ?? null,
            'sobat_id' => $data['sobatId'] ?? null,
            'full_name' => trim($data['nama']),
            'partner_type' => $data['jenis'],
            'phone' => $data['wa'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['alamat'] ?? null,
            'district' => $data['kecamatan'] ?? null,
            'village' => $data['desa'] ?? null,
            'province' => $data['provinsi'] ?? null,
            'verification_status' => 'MENUNGGU_VERIFIKASI',
            'is_active' => true,
            'is_demo' => false,
            'created_by' => $request->user()->id,
        ]);
        app(AuditLogger::class)->record($request, 'PARTNER_CREATED', 'Partner', $partner->id, null, [
            'full_name' => $partner->full_name,
            'sobat_id' => $partner->sobat_id,
            'partner_type' => $partner->partner_type,
            'verification_status' => $partner->verification_status,
        ]);

        return response()->json($this->detail($partner, $request->user()), 201);
    }

    public function update(Request $request, Partner $partner): JsonResponse
    {
        $data = $request->validate([
            'nama' => ['sometimes', 'required', 'string', 'max:180'],
            'sobatId' => ['nullable', 'string', 'max:60', Rule::unique('partners', 'sobat_id')->ignore($partner->id)],
            'partnerCode' => ['nullable', 'string', 'max:50', Rule::unique('partners', 'partner_code')->ignore($partner->id)],
            'jenis' => ['sometimes', 'required', Rule::in(['PERORANGAN', 'LEMBAGA', 'PERUSAHAAN'])],
            'wa' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'kecamatan' => ['nullable', 'string', 'max:100'],
            'desa' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
        ]);

        $mapping = [
            'nama' => 'full_name', 'sobatId' => 'sobat_id', 'partnerCode' => 'partner_code',
            'jenis' => 'partner_type', 'wa' => 'phone', 'email' => 'email', 'alamat' => 'address',
            'kecamatan' => 'district', 'desa' => 'village', 'provinsi' => 'province',
        ];
        $auditFields = ['full_name', 'sobat_id', 'partner_code', 'partner_type', 'phone', 'email', 'address', 'district', 'village', 'province', 'verification_status'];
        $before = $partner->only($auditFields);
        foreach ($mapping as $input => $column) {
            if (array_key_exists($input, $data)) {
                $partner->{$column} = $input === 'nama' ? trim($data[$input]) : $data[$input];
            }
        }
        $changed = $partner->isDirty(array_values(array_intersect_key($mapping, $data)));
        $partner->updated_by = $request->user()->id;
        if ($changed) {
            $partner->verification_status = 'MENUNGGU_VERIFIKASI';
            $partner->verification_note = null;
            $partner->verified_by = null;
            $partner->verified_at = null;
        }
        $partner->save();
        app(AuditLogger::class)->record($request, 'PARTNER_UPDATED', 'Partner', $partner->id, $before, $partner->only($auditFields));

        return response()->json($this->detail($partner->fresh(), $request->user()));
    }

    public function verify(Request $request, Partner $partner): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['TERVERIFIKASI', 'PERLU_PERBAIKAN', 'TIDAK_MEMENUHI'])],
            'catatan' => ['nullable', 'string', 'max:2000', Rule::requiredIf(fn () => $request->input('status') !== 'TERVERIFIKASI')],
        ]);
        $user = $request->user();

        if ($partner->verification_status !== 'MENUNGGU_VERIFIKASI') {
            return response()->json(['message' => 'Mitra tidak sedang menunggu verifikasi.'], 409);
        }
        if ($partner->created_by === $user->id || $partner->updated_by === $user->id) {
            return response()->json(['message' => 'Pemeriksa harus berbeda dari pembuat atau editor data terakhir.'], 403);
        }

        $before = ['verification_status' => $partner->verification_status, 'verification_note' => $partner->verification_note];
        DB::transaction(function () use ($partner, $user, $data) {
            $partner->forceFill([
                'verification_status' => $data['status'],
                'verification_note' => $data['catatan'] ?? null,
                'verified_by' => $user->id,
                'verified_at' => now(),
                'updated_by' => $user->id,
            ])->save();

            PartnerVerificationHistory::create([
                'partner_id' => $partner->id,
                'status' => $data['status'],
                'note' => $data['catatan'] ?? null,
                'reviewer_id' => $user->id,
                'reviewed_at' => now(),
            ]);
        });
        app(AuditLogger::class)->record($request, 'PARTNER_VERIFIED', 'Partner', $partner->id, $before, [
            'verification_status' => $partner->verification_status,
            'verification_note' => $partner->verification_note,
        ]);

        return response()->json($this->detail($partner->fresh(), $user));
    }

    private function canRead(User $user, Partner $partner): bool
    {
        if ($user->hasRole(['ADMIN_BPS', 'ADMIN_MITRA', 'OPERATOR'])) return true;
        if ($user->hasRole('PETUGAS_MITRA')) return (int) $user->partner_id === (int) $partner->id;
        if ($user->hasRole('PEMERIKSA')) return $partner->verification_status === 'MENUNGGU_VERIFIKASI';
        return $user->hasRole('PJK') && AssignmentMember::query()
            ->where('partner_id', $partner->id)
            ->whereHas('assignment', fn ($assignment) => $assignment
                ->where('coordinator_id', $user->id)
                ->orWhereHas('activity', fn ($activity) => $activity->where('responsible_user_id', $user->id)))
            ->exists();
    }

    private function summary(Partner $partner): array
    {
        return [
            'id' => (string) $partner->id,
            'partnerCode' => $partner->partner_code ?? '',
            'sobatId' => $partner->sobat_id ?? '',
            'nama' => $partner->full_name,
            'jenis' => $partner->partner_type,
            'statusVerifikasi' => $partner->verification_status,
            'aktif' => (bool) $partner->is_active,
            'kecamatan' => $partner->district ?? '',
            'desa' => $partner->village ?? '',
            'demo' => (bool) $partner->is_demo,
            'createdAt' => $partner->created_at?->getTimestampMs() ?? 0,
        ];
    }

    private function detail(Partner $partner, User $user): array
    {
        return [
            ...$this->summary($partner),
            'wa' => $partner->phone ?? '',
            'email' => $partner->email ?? '',
            'alamat' => $partner->address ?? '',
            'provinsi' => $partner->province ?? '',
            'catatanVerifikasi' => $partner->verification_note ?? '',
            'canVerify' => $partner->verification_status === 'MENUNGGU_VERIFIKASI'
                && $user->hasRole(['ADMIN_BPS', 'ADMIN_MITRA', 'PEMERIKSA'])
                && (int) $partner->created_by !== (int) $user->id
                && (int) $partner->updated_by !== (int) $user->id,
            'updatedAt' => $partner->updated_at?->getTimestampMs() ?? 0,
        ];
    }
}
