<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\AssignmentMember;
use App\Models\AssignmentWorkItem;
use App\Models\Document;
use App\Models\WorkEvidence;
use App\Models\WorkReport;
use App\Models\WorkReportItem;
use App\Models\WorkReviewAction;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['DRAFT', 'DIAJUKAN', 'PERLU_PERBAIKAN', 'DITERIMA', 'DITOLAK'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $query = $this->scopedReports($request->user())->with(['member.partner', 'member.assignment.activity', 'spk', 'items', 'evidence']);
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);
        $total = (clone $query)->count();
        $items = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();
        return response()->json([
            'items' => $items->map(fn (WorkReport $report) => $this->resource($report))->values(),
            'total' => $total, 'limit' => $limit, 'offset' => $offset,
        ]);
    }

    public function show(Request $request, WorkReport $report): JsonResponse
    {
        abort_unless($this->scopedReports($request->user())->whereKey($report->id)->exists(), 403, 'Laporan kerja di luar lingkup akun.');
        return response()->json($this->resource($report->load(['member.partner', 'member.assignment.activity', 'spk', 'items', 'evidence'])));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('PETUGAS_MITRA') && $user->partner_id, 403);
        $data = $request->validate([
            'assignmentMemberId' => ['required', 'integer', 'exists:assignment_members,id'],
            'spkDocumentId' => ['required', 'integer', 'exists:documents,id'],
            'summary' => ['required', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.assignmentWorkItemId' => ['required', 'integer', 'distinct', 'exists:assignment_work_items,id'],
            'items.*.actualVolume' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'items.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        $member = AssignmentMember::with(['assignment', 'workItems', 'partner'])->findOrFail($data['assignmentMemberId']);
        if ((int) $member->partner_id !== (int) $user->partner_id || !$member->partner->is_active || $member->partner->is_demo) {
            return response()->json(['message' => 'Penugasan ini bukan milik akun mitra atau tidak aktif.'], 403);
        }
        if (in_array($member->assignment->status, ['SELESAI', 'DITUTUP', 'DIBATALKAN'], true)) {
            return response()->json(['message' => 'Penugasan sudah ditutup.'], 409);
        }
        $spk = Document::findOrFail($data['spkDocumentId']);
        if ($spk->document_type !== 'SPK' || $spk->status !== 'FINAL' || (int) $spk->assignment_member_id !== (int) $member->id) {
            return response()->json(['message' => 'Laporan harus terhubung dengan SPK final milik penugasan ini.'], 409);
        }
        $expectedItems = $this->itemsForSpk($member, $spk);
        $expected = $expectedItems->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $submitted = collect($data['items'])->pluck('assignmentWorkItemId')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($expected !== $submitted) {
            return response()->json(['message' => 'Rincian laporan harus memuat semua target penugasan tepat satu kali.'], 422);
        }
        if (WorkReport::where('assignment_member_id', $member->id)->where('spk_document_id', $spk->id)
            ->whereIn('status', ['DRAFT', 'DIAJUKAN', 'PERLU_PERBAIKAN', 'DITERIMA'])->exists()) {
            return response()->json(['message' => 'Laporan aktif untuk SPK ini sudah ada.'], 409);
        }

        return DB::transaction(function () use ($request, $data, $member, $spk) {
            $report = WorkReport::create([
                'assignment_member_id' => $member->id,
                'spk_document_id' => $spk->id,
                'status' => 'DRAFT',
                'summary' => trim($data['summary']),
                'submitted_by' => $request->user()->id,
            ]);
            foreach ($data['items'] as $item) {
                $target = $member->workItems->firstWhere('id', (int) $item['assignmentWorkItemId']);
                WorkReportItem::create([
                    'work_report_id' => $report->id,
                    'assignment_work_item_id' => $target->id,
                    'description' => $target->description,
                    'actual_volume' => $item['actualVolume'],
                    'unit' => $target->unit,
                    'note' => $item['note'] ?? null,
                ]);
            }
            app(AuditLogger::class)->record($request, 'WORK_REPORT_CREATED', 'WorkReport', $report->id, null, [
                'assignment_member_id' => $member->id,
                'spk_document_id' => $spk->id,
                'status' => $report->status,
                'item_count' => count($data['items']),
            ]);
            return response()->json($this->resource($report->load(['member.partner', 'member.assignment.activity', 'spk', 'items', 'evidence'])), 201);
        });
    }

    public function update(Request $request, WorkReport $report): JsonResponse
    {
        abort_unless($this->isOwner($request->user(), $report), 403);
        if (!in_array($report->status, ['DRAFT', 'PERLU_PERBAIKAN'], true)) {
            return response()->json(['message' => 'Laporan hanya dapat diubah sebagai draft atau setelah permintaan perbaikan.'], 409);
        }
        $data = $request->validate([
            'summary' => ['required', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.assignmentWorkItemId' => ['required', 'integer', 'distinct', 'exists:assignment_work_items,id'],
            'items.*.actualVolume' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'items.*.note' => ['nullable', 'string', 'max:2000'],
        ]);
        $member = $report->member()->with('workItems')->firstOrFail();
        $spk = Document::findOrFail($report->spk_document_id);
        $expectedItems = $this->itemsForSpk($member, $spk);
        $expected = $expectedItems->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $submitted = collect($data['items'])->pluck('assignmentWorkItemId')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($expected !== $submitted) {
            return response()->json(['message' => 'Rincian laporan harus memuat semua target pekerjaan tepat satu kali.'], 422);
        }

        $before = [
            'status' => $report->status,
            'summary' => $report->summary,
            'items' => $report->items()->where('revision', $report->revision)->get(['description', 'actual_volume', 'unit', 'note'])->toArray(),
        ];
        $nextRevision = (int) $report->revision + 1;
        DB::transaction(function () use ($request, $data, $report, $expectedItems, $before, $nextRevision) {
            $report->forceFill(['summary' => trim($data['summary']), 'revision' => $nextRevision])->save();
            foreach ($data['items'] as $item) {
                $target = $expectedItems->firstWhere('id', (int) $item['assignmentWorkItemId']);
                $report->items()->create([
                    'assignment_work_item_id' => $target->id,
                    'description' => $target->description,
                    'actual_volume' => $item['actualVolume'],
                    'unit' => $target->unit,
                    'note' => $item['note'] ?? null,
                    'revision' => $nextRevision,
                ]);
            }
            app(AuditLogger::class)->record($request, 'WORK_REPORT_UPDATED', 'WorkReport', $report->id, $before, [
                'status' => $report->status,
                'summary' => $report->summary,
                'revision' => $nextRevision,
                'item_count' => count($data['items']),
            ]);
        });

        return response()->json($this->resource($report->fresh()->load(['member.partner', 'member.assignment.activity', 'spk', 'items', 'evidence'])));
    }

    public function submit(Request $request, WorkReport $report): JsonResponse
    {
        abort_unless($this->isOwner($request->user(), $report), 403);
        if ($report->status !== 'DRAFT' && $report->status !== 'PERLU_PERBAIKAN') {
            return response()->json(['message' => 'Laporan tidak dapat diajukan pada status saat ini.'], 409);
        }
        if (!$report->items()->exists()) return response()->json(['message' => 'Laporan harus memiliki rincian target dan realisasi.'], 409);
        $before = $report->status;
        $report->forceFill(['status' => 'DIAJUKAN', 'submitted_by' => $request->user()->id, 'submitted_at' => now()])->save();
        app(AuditLogger::class)->record($request, 'WORK_REPORT_SUBMITTED', 'WorkReport', $report->id, ['status' => $before], ['status' => 'DIAJUKAN']);
        return response()->json($this->resource($report->fresh()->load(['member.partner', 'member.assignment.activity', 'spk', 'items', 'evidence'])));
    }

    public function review(Request $request, WorkReport $report): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(['PJK', 'PEMERIKSA', 'ADMIN_BPS']), 403);
        abort_unless($this->scopedReports($user)->whereKey($report->id)->exists(), 403, 'Laporan kerja di luar lingkup akun.');
        if ($report->status !== 'DIAJUKAN') return response()->json(['message' => 'Laporan tidak sedang menunggu pemeriksaan.'], 409);
        if ($report->submitted_by === $user->id) return response()->json(['message' => 'Pemeriksa harus berbeda dari pengaju laporan.'], 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['DITERIMA', 'PERLU_PERBAIKAN', 'DITOLAK'])],
            'note' => ['nullable', 'string', 'max:3000', Rule::requiredIf(fn () => $request->input('decision') !== 'DITERIMA')],
        ]);
        if ($data['decision'] === 'DITERIMA' && $report->evidence()->where('scan_status', '!=', 'CLEAN_MANUALLY_REVIEWED')->exists()) {
            return response()->json(['message' => 'Lampiran harus diperiksa manual sebelum hasil diterima. Antivirus otomatis belum terpasang.'], 409);
        }
        $before = $report->status;
        DB::transaction(function () use ($request, $report, $data) {
            $report->forceFill([
                'status' => $data['decision'],
                'accepted_by' => $data['decision'] === 'DITERIMA' ? $request->user()->id : null,
                'accepted_at' => $data['decision'] === 'DITERIMA' ? now() : null,
            ])->save();
            WorkReviewAction::create([
                'work_report_id' => $report->id,
                'reviewer_id' => $request->user()->id,
                'outcome' => $data['decision'],
                'note' => $data['note'] ?? null,
                'revision' => $report->revision,
                'reviewed_at' => now(),
            ]);
            app(AuditLogger::class)->record($request, 'WORK_REPORT_REVIEWED', 'WorkReport', $report->id,
                ['status' => 'DIAJUKAN'], ['status' => $data['decision'], 'note' => $data['note'] ?? null]);
        });
        return response()->json($this->resource($report->fresh()->load(['member.partner', 'member.assignment.activity', 'spk', 'items', 'evidence'])));
    }

    public function uploadEvidence(Request $request, WorkReport $report): JsonResponse
    {
        abort_unless($this->isOwner($request->user(), $report), 403);
        if (!in_array($report->status, ['DRAFT', 'PERLU_PERBAIKAN'], true)) {
            return response()->json(['message' => 'Lampiran hanya dapat ditambahkan pada draft atau laporan yang perlu perbaikan.'], 409);
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx,xlsx', 'max:'.(int) ceil(config('sikomit.documents.max_evidence_bytes', 10485760) / 1024)],
        ]);
        $file = $data['file'];
        $path = $file->storeAs("sikomit/work-evidence/{$report->id}", Str::uuid().'.'.$file->extension(), 'local');
        $evidence = WorkEvidence::create([
            'work_report_id' => $report->id,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'scan_status' => 'PENDING_REVIEW',
            'uploaded_by' => $request->user()->id,
        ]);
        app(AuditLogger::class)->record($request, 'WORK_EVIDENCE_UPLOADED', 'WorkEvidence', $evidence->id, null, [
            'work_report_id' => $report->id,
            'mime_type' => $evidence->mime_type,
            'size_bytes' => $evidence->size_bytes,
            'sha256' => $evidence->sha256,
        ]);
        return response()->json([
            'id' => (string) $evidence->id,
            'originalFilename' => $evidence->original_filename,
            'mimeType' => $evidence->mime_type,
            'sizeBytes' => $evidence->size_bytes,
            'scanStatus' => $evidence->scan_status,
        ], 201);
    }

    public function validateEvidence(Request $request, WorkReport $report, WorkEvidence $evidence): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(['ADMIN_BPS', 'PJK', 'PEMERIKSA']), 403);
        abort_unless($this->scopedReports($user)->whereKey($report->id)->exists() && (int) $evidence->work_report_id === (int) $report->id, 403);
        if ($report->status !== 'DIAJUKAN') {
            return response()->json(['message' => 'Lampiran hanya diperiksa saat laporan menunggu pemeriksaan.'], 409);
        }
        $data = $request->validate([
            'decision' => ['required', Rule::in(['CLEAN_MANUALLY_REVIEWED', 'REJECTED'])],
            'note' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => $request->input('decision') === 'REJECTED')],
        ]);
        $before = ['scan_status' => $evidence->scan_status];
        $evidence->forceFill([
            'scan_status' => $data['decision'],
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ])->save();
        app(AuditLogger::class)->record($request, 'WORK_EVIDENCE_REVIEWED', 'WorkEvidence', $evidence->id, $before, [
            'scan_status' => $evidence->scan_status,
            'note' => $data['note'] ?? null,
        ]);
        return response()->json(['ok' => true, 'scanStatus' => $evidence->scan_status]);
    }

    public function downloadEvidence(Request $request, WorkReport $report, WorkEvidence $evidence): StreamedResponse|JsonResponse
    {
        if (!$this->scopedReports($request->user())->whereKey($report->id)->exists() || $evidence->work_report_id !== $report->id) {
            return response()->json(['message' => 'Lampiran di luar lingkup akun.'], 403);
        }
        if (!Storage::disk('local')->exists($evidence->storage_path)) {
            return response()->json(['message' => 'File lampiran tidak tersedia.'], 404);
        }
        return Storage::disk('local')->download($evidence->storage_path, $evidence->original_filename, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function scopedReports(User $user): Builder
    {
        $query = WorkReport::query();
        if ($user->hasRole('ADMIN_BPS')) return $query;
        if ($user->hasRole('PETUGAS_MITRA') && $user->partner_id) {
            return $query->whereHas('member', fn (Builder $member) => $member->where('partner_id', $user->partner_id));
        }
        if ($user->hasRole('PJK')) {
            return $query->whereHas('member.assignment', fn (Builder $assignment) => $assignment
                ->where('coordinator_id', $user->id)
                ->orWhereHas('activity', fn (Builder $activity) => $activity->where('responsible_user_id', $user->id)));
        }
        if ($user->hasRole('OPERATOR')) return $query->where('submitted_by', $user->id);
        if ($user->hasRole('PEMERIKSA')) return $query->where('status', 'DIAJUKAN');
        abort(403, 'Tidak memiliki lingkup laporan kerja.');
    }

    private function isOwner(User $user, WorkReport $report): bool
    {
        return $user->hasRole('PETUGAS_MITRA') && (int) $user->partner_id === (int) $report->member?->partner_id;
    }

    private function itemsForSpk(AssignmentMember $member, Document $spk)
    {
        $items = $member->workItems;
        if ($spk->document_mode === 'TERPISAH' && $spk->worker_category !== 'PENDATAAN_DAN_PENGOLAHAN') {
            $items = $items->where('work_category', $spk->worker_category)->values();
        }
        return $items;
    }

    private function resource(WorkReport $report): array
    {
        return [
            'id' => (string) $report->id,
            'assignmentMemberId' => (string) $report->assignment_member_id,
            'spkDocumentId' => $report->spk_document_id ? (string) $report->spk_document_id : '',
            'partnerId' => (string) ($report->member?->partner_id ?? ''),
            'partnerName' => $report->member?->partner?->full_name ?? '',
            'activityName' => $report->member?->assignment?->activity?->name ?? '',
            'status' => $report->status,
            'demo' => (bool) ($report->member?->assignment?->is_demo ?? false),
            'summary' => $report->summary ?? '',
            'items' => $report->items->where('revision', $report->revision)->values()->map(fn ($item) => [
                'assignmentWorkItemId' => $item->assignment_work_item_id ? (string) $item->assignment_work_item_id : '',
                'description' => $item->description,
                'target' => (float) ($item->assignmentWorkItem?->target_volume ?? 0),
                'actualVolume' => (float) $item->actual_volume,
                'unit' => $item->unit ?? '',
                'note' => $item->note ?? '',
            ])->values(),
            'evidence' => $report->evidence->map(fn (WorkEvidence $file) => [
                'id' => (string) $file->id,
                'originalFilename' => $file->original_filename,
                'mimeType' => $file->mime_type,
                'sizeBytes' => $file->size_bytes,
                'sha256' => $file->sha256,
                'scanStatus' => $file->scan_status,
            ])->values(),
            'submittedAt' => $report->submitted_at?->getTimestampMs(),
            'acceptedAt' => $report->accepted_at?->getTimestampMs(),
        ];
    }
}
