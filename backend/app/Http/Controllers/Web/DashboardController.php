<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\AssignmentMember;
use App\Models\ArchiveEntry;
use App\Models\Document;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 403);
        if ($user->must_change_password) return view('auth.change-password', ['forced' => true]);

        $partners = Partner::query()->where('is_demo', false);
        $activities = Activity::query()->where('is_demo', false);
        $assignments = Assignment::query()->where('is_demo', false);

        if ($user->hasRole('ADMIN_BPS')) {
            // Full administrative scope.
        } elseif ($user->hasRole('ADMIN_MITRA')) {
            $activities->whereRaw('1 = 0');
            $assignments->whereRaw('1 = 0');
        } elseif ($user->hasRole('PJK')) {
            $activities->where(fn (Builder $query) => $query->where('responsible_user_id', $user->id)
                ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('coordinator_id', $user->id)));
            $assignments->where(fn (Builder $query) => $query->where('coordinator_id', $user->id)
                ->orWhereHas('activity', fn (Builder $activity) => $activity->where('responsible_user_id', $user->id)));
            $partners->whereIn('id', AssignmentMember::query()->whereHas('assignment', fn (Builder $assignment) => $assignment
                ->where('coordinator_id', $user->id)
                ->orWhereHas('activity', fn (Builder $activity) => $activity->where('responsible_user_id', $user->id)))
                ->select('partner_id'));
        } elseif ($user->hasRole('OPERATOR')) {
            $activities->where(fn (Builder $query) => $query->where('created_by', $user->id)->orWhere('responsible_user_id', $user->id));
            $assignments->where('created_by', $user->id);
            $partners->whereIn('id', AssignmentMember::query()->whereHas('assignment', fn (Builder $assignment) => $assignment->where('created_by', $user->id))->select('partner_id'));
        } elseif ($user->hasRole('PEMERIKSA')) {
            $partners->where('verification_status', 'MENUNGGU_VERIFIKASI');
            $activities->whereRaw('1 = 0');
            $assignments->whereRaw('1 = 0');
        } elseif ($user->hasRole('PETUGAS_MITRA') && $user->partner_id) {
            $partners->whereKey($user->partner_id);
            $assignments->whereHas('members', fn (Builder $member) => $member->where('partner_id', $user->partner_id));
            $activities->whereHas('assignments.members', fn (Builder $member) => $member->where('partner_id', $user->partner_id));
        } else {
            abort(403);
        }

        $metrics = [
            'partners' => (clone $partners)->count(),
            'partnersPending' => (clone $partners)->where('verification_status', 'MENUNGGU_VERIFIKASI')->count(),
            'activities' => (clone $activities)->count(),
            'activitiesActive' => (clone $activities)->where('status', 'AKTIF')->count(),
            'assignments' => (clone $assignments)->count(),
            'assignmentsActive' => (clone $assignments)->whereNotIn('status', ['SELESAI', 'DITUTUP', 'DIBATALKAN'])->count(),
        ];
        $demoCounts = $user->hasRole('ADMIN_BPS') ? [
            'partners' => Partner::query()->where('is_demo', true)->count(),
            'activities' => Activity::query()->where('is_demo', true)->count(),
            'assignments' => Assignment::query()->where('is_demo', true)->count(),
            'spk' => Document::query()->where('is_demo', true)->where('document_type', 'SPK')->count(),
            'bast' => Document::query()->where('is_demo', true)->where('document_type', 'BAST')->count(),
            'archives' => ArchiveEntry::query()->whereHas('document', fn (Builder $document) => $document->where('is_demo', true))->count(),
        ] : ['partners' => 0, 'activities' => 0, 'assignments' => 0, 'spk' => 0, 'bast' => 0, 'archives' => 0];

        return view('dashboard.index', [
            'user' => $user->load('roles'),
            'metrics' => $metrics,
            'demoCounts' => $demoCounts,
            'recentActivities' => (clone $activities)->latest()->limit(5)->get(),
            'recentAssignments' => (clone $assignments)->with(['activity', 'members.partner'])->latest()->limit(5)->get(),
        ]);
    }
}
