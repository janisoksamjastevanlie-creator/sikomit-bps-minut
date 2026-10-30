<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\AssignmentMember;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $partners = Partner::query()->where('is_demo', false);
        $activities = Activity::query()->where('is_demo', false);
        $assignments = Assignment::query()->where('is_demo', false);

        if ($user->hasRole('ADMIN_BPS')) {
            // Full administrative aggregate.
        } elseif ($user->hasRole('ADMIN_MITRA')) {
            $activities->whereRaw('1 = 0');
            $assignments->whereRaw('1 = 0');
        } elseif ($user->hasRole('PETUGAS_MITRA') && $user->partner_id) {
            $partners->whereKey($user->partner_id);
            $assignments->whereHas('members', fn (Builder $member) => $member->where('partner_id', $user->partner_id));
            $activities->whereHas('assignments.members', fn (Builder $member) => $member->where('partner_id', $user->partner_id));
        } elseif ($user->hasRole('PJK')) {
            $activities->where(fn (Builder $builder) => $builder
                ->where('responsible_user_id', $user->id)
                ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('coordinator_id', $user->id)));
            $assignments->where(fn (Builder $builder) => $builder
                ->where('coordinator_id', $user->id)
                ->orWhereHas('activity', fn (Builder $activity) => $activity->where('responsible_user_id', $user->id)));
            $partners->whereIn('id', AssignmentMember::query()->whereHas('assignment', fn (Builder $assignment) => $assignment
                ->where('coordinator_id', $user->id)
                ->orWhereHas('activity', fn (Builder $activity) => $activity->where('responsible_user_id', $user->id)))
                ->select('partner_id'));
        } elseif ($user->hasRole('OPERATOR')) {
            $activities->where(fn (Builder $builder) => $builder->where('created_by', $user->id)->orWhere('responsible_user_id', $user->id));
            $assignments->where('created_by', $user->id);
            $partners->whereIn('id', AssignmentMember::query()->whereHas('assignment', fn (Builder $assignment) => $assignment->where('created_by', $user->id))->select('partner_id'));
        } elseif ($user->hasRole('PEMERIKSA')) {
            $partners->where('verification_status', 'MENUNGGU_VERIFIKASI');
            $activities->whereRaw('1 = 0');
            $assignments->whereRaw('1 = 0');
        } else {
            return response()->json(['message' => 'Tidak memiliki lingkup dashboard.'], 403);
        }

        $partnerRows = (clone $partners)->get(['id', 'is_active', 'verification_status']);
        $activityQuery = clone $activities;
        $assignmentQuery = clone $assignments;

        return response()->json([
            'demoMode' => (bool) config('app.demo_mode', false),
            'generatedAt' => now()->toIso8601String(),
            'mitra' => [
                'total' => $partnerRows->count(),
                'aktif' => $partnerRows->where('is_active', true)->count(),
                'menungguVerifikasi' => $partnerRows->where('verification_status', 'MENUNGGU_VERIFIKASI')->count(),
                'perluPerbaikan' => $partnerRows->where('verification_status', 'PERLU_PERBAIKAN')->count(),
                'terverifikasi' => $partnerRows->where('verification_status', 'TERVERIFIKASI')->count(),
            ],
            'kegiatan' => [
                'total' => (clone $activityQuery)->count(),
                'aktif' => (clone $activityQuery)->where('status', 'AKTIF')->count(),
            ],
            'penugasan' => [
                'total' => (clone $assignmentQuery)->count(),
                'aktif' => (clone $assignmentQuery)->whereNotIn('status', ['SELESAI', 'DITUTUP', 'DIBATALKAN'])->count(),
                'dalamPelaksanaan' => (clone $assignmentQuery)->where('status', 'DALAM_PELAKSANAAN')->count(),
            ],
            'spk' => $this->documentCounts('SPK'),
            'pekerjaan' => $this->workCounts($assignmentQuery),
            'bast' => $this->documentCounts('BAST'),
            'arsip' => ['total' => $this->archiveCount()],
        ]);
    }

    private function documentCounts(string $type): array
    {
        $empty = [
            'draft' => 0,
            'menungguPemeriksaan' => 0,
            'menungguPersetujuan' => 0,
            'menungguPenandatanganan' => 0,
            'final' => 0,
        ];
        if (!Schema::hasTable('documents')) return $empty;

        $query = DB::table('documents')->where('document_type', $type)->where('is_demo', false);
        return [
            'draft' => (clone $query)->where('status', 'DRAFT')->count(),
            'menungguPemeriksaan' => (clone $query)->where('status', 'MENUNGGU_PEMERIKSAAN')->count(),
            'menungguPersetujuan' => (clone $query)->where('status', 'MENUNGGU_PERSETUJUAN')->count(),
            'menungguPenandatanganan' => (clone $query)->where('status', 'MENUNGGU_TANDATANGAN')->count(),
            'final' => (clone $query)->where('status', 'FINAL')->count(),
        ];
    }

    private function workCounts(Builder $assignments): array
    {
        $empty = ['dalamPelaksanaan' => (clone $assignments)->where('status', 'DALAM_PELAKSANAAN')->count(),
            'menungguPemeriksaan' => 0, 'perluPerbaikan' => 0, 'terverifikasi' => 0];
        if (!Schema::hasTable('work_reports')) return $empty;

        $memberIds = DB::table('assignment_members')
            ->whereIn('assignment_id', (clone $assignments)->select('id'))
            ->select('id');
        $query = DB::table('work_reports')->whereIn('assignment_member_id', $memberIds);
        return [
            'dalamPelaksanaan' => (clone $assignments)->where('status', 'DALAM_PELAKSANAAN')->count(),
            'menungguPemeriksaan' => (clone $query)->where('status', 'DIAJUKAN')->count(),
            'perluPerbaikan' => (clone $query)->where('status', 'PERLU_PERBAIKAN')->count(),
            'terverifikasi' => (clone $query)->where('status', 'DITERIMA')->count(),
        ];
    }

    private function archiveCount(): int
    {
        if (!Schema::hasTable('archive_entries')) return 0;
        return DB::table('archive_entries')
            ->join('documents', 'documents.id', '=', 'archive_entries.document_id')
            ->where('documents.is_demo', false)
            ->count();
    }
}
