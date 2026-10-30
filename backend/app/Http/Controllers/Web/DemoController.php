<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Document;
use App\Models\Partner;
use App\Models\WorkReport;
use Illuminate\View\View;

class DemoController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.demo', [
            'partners' => Partner::query()->where('is_demo', true)->orderBy('partner_code')->get(),
            'activities' => Activity::query()->where('is_demo', true)->with('targets')->orderBy('code')->get(),
            'assignments' => Assignment::query()->where('is_demo', true)->with(['activity', 'members.partner', 'members.workItems'])->orderBy('id')->get(),
            'reports' => WorkReport::query()->whereHas('member.assignment', fn ($query) => $query->where('is_demo', true))
                ->with(['member.partner', 'member.assignment.activity', 'items', 'spk'])->latest()->get(),
            'documents' => Document::query()->where('is_demo', true)->where('status', 'SIMULASI_SELESAI')
                ->with(['activity', 'assignmentMember.partner', 'parent'])->orderBy('document_type')->get(),
        ]);
    }
}
