<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\ArchiveEntry;
use App\Models\AssignmentMember;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentSignature;
use App\Models\DocumentTemplateVersion;
use App\Models\WorkReport;
use App\Models\WorkReportItem;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentRenderer;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    private const CATEGORIES = ['PENDATAAN_LAPANGAN', 'PENGOLAHAN', 'PENDATAAN_DAN_PENGOLAHAN'];
    private const MODES = ['TERPISAH', 'GABUNGAN'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['SPK', 'BAST'])],
            'status' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $query = $this->visibleDocuments($request->user())->with(['activity', 'assignmentMember.partner', 'parent']);
        if (!empty($filters['type'])) $query->where('document_type', $filters['type']);
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);
        $total = (clone $query)->count();
        $documents = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return response()->json([
            'items' => $documents->map(fn (Document $document) => $this->resource($document))->values(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        abort_unless($this->visibleDocuments($request->user())->whereKey($document->id)->exists(), 403, 'Dokumen di luar lingkup akun.');
        return response()->json($this->resource($document->load(['activity', 'assignmentMember.partner', 'parent', 'templateVersion'])));
    }

    public function createSpkDraft(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(['ADMIN_BPS', 'PJK', 'OPERATOR']), 403);
        $data = $request->validate([
            'assignmentMemberId' => ['required', 'integer', 'exists:assignment_members,id'],
            'documentMode' => ['nullable', Rule::in(self::MODES)],
            'documentNumber' => ['nullable', 'string', 'max:120', 'unique:documents,document_number'],
        ]);
        $member = AssignmentMember::with(['partner', 'assignment.activity', 'workItems'])
            ->findOrFail($data['assignmentMemberId']);
        $activity = $member->assignment->activity;
        if (!$user->hasRole('ADMIN_BPS') &&
            !($user->hasRole('PJK') && ($member->assignment->coordinator_id === $user->id || $activity->responsible_user_id === $user->id)) &&
            !($user->hasRole('OPERATOR') && $member->assignment->created_by === $user->id)) {
            return response()->json(['message' => 'Penugasan di luar lingkup akun.'], 403);
        }
        if (!$member->partner->is_active || $member->partner->is_demo || $member->partner->verification_status !== 'TERVERIFIKASI') {
            return response()->json(['message' => 'SPK hanya dapat dibuat untuk mitra aktif dan terverifikasi non-demo.'], 409);
        }
        if ($member->assignment->status === 'DIBATALKAN' || $activity->status !== 'AKTIF') {
            return response()->json(['message' => 'Penugasan atau kegiatan tidak aktif.'], 409);
        }

        $mode = $data['documentMode'] ?? 'TERPISAH';
        $category = $member->contract_category;
        if ($mode === 'GABUNGAN') {
            if (!config('sikomit.documents.combined_mode_enabled', false)) {
                return response()->json(['message' => 'Mode dokumen gabungan belum diaktifkan oleh pejabat berwenang.'], 409);
            }
            if ($category !== 'PENDATAAN_DAN_PENGOLAHAN') {
                return response()->json(['message' => 'Mode gabungan hanya untuk petugas dengan dua jenis pekerjaan.'], 422);
            }
            $categories = ['PENDATAAN_DAN_PENGOLAHAN'];
        } elseif ($category === 'PENDATAAN_DAN_PENGOLAHAN') {
            $categories = ['PENDATAAN_LAPANGAN', 'PENGOLAHAN'];
        } else {
            $categories = [$category];
        }

        if (count($categories) > 1 && !empty($data['documentNumber'])) {
            return response()->json(['message' => 'Setiap SPK terpisah memerlukan nomor resmi masing-masing setelah draft dibuat.'], 422);
        }
        foreach ($categories as $workerCategory) {
            if (Document::query()->where('document_type', 'SPK')
                ->where('assignment_member_id', $member->id)
                ->where('worker_category', $workerCategory)
                ->where('document_mode', $mode)
                ->whereNotIn('status', ['DIBATALKAN', 'DIBATALKAN_ADMIN'])
                ->exists()) {
                return response()->json(['message' => "SPK {$workerCategory} untuk penugasan ini sudah ada."], 409);
            }
            $hasCategoryWork = $member->workItems->contains(fn ($item) =>
                $workerCategory === 'PENDATAAN_DAN_PENGOLAHAN' || $item->work_category === $workerCategory
            );
            if (!$hasCategoryWork) return response()->json(['message' => "Tidak ada target kerja untuk {$workerCategory}."], 409);
        }

        $created = DB::transaction(function () use ($request, $user, $member, $activity, $mode, $categories, $data) {
            $createdDocuments = [];
            foreach ($categories as $workerCategory) {
                $templateVersion = $this->approvedTemplate('SPK', $workerCategory, $mode);
                $items = $member->workItems->filter(fn ($item) =>
                    $workerCategory === 'PENDATAAN_DAN_PENGOLAHAN' || $item->work_category === $workerCategory
                )->values()->map(fn ($item) => [
                    'assignmentWorkItemId' => (string) $item->id,
                    'uraian' => $item->description,
                    'target' => (float) $item->target_volume,
                    'satuan' => $item->unit ?? '',
                    'wilayah' => $item->territory ?? '',
                    'jadwalMulai' => $item->starts_on?->format('Y-m-d') ?? '',
                    'jadwalSelesai' => $item->ends_on?->format('Y-m-d') ?? '',
                    'workCategory' => $item->work_category,
                    'documentValue' => '',
                ])->all();
                $snapshot = [
                    'document_number' => $data['documentNumber'] ?? '',
                    'document_date' => '',
                    'partner_name' => $member->partner->full_name,
                    'partner_code' => $member->partner->partner_code ?? '',
                    'partner_sobat_id' => $member->partner->sobat_id ?? '',
                    'activity_name' => $activity->name,
                    'activity_code' => $activity->code,
                    'activity_year' => $activity->year,
                    'activity_period' => $activity->period ?? '',
                    'activity_territory' => $activity->territory ?? '',
                    'responsible_name' => $activity->responsible_name ?? '',
                    'work_period' => $member->assignment->starts_on->format('d/m/Y').' - '.$member->assignment->ends_on->format('d/m/Y'),
                    'work_items' => $items,
                    'document_status' => 'DRAF',
                    'draft_banner' => 'DRAF — BELUM FINAL',
                ];
                $document = Document::create([
                    'document_type' => 'SPK',
                    'worker_category' => $workerCategory,
                    'document_mode' => $mode,
                    'document_number' => $data['documentNumber'] ?? null,
                    'activity_id' => $activity->id,
                    'assignment_id' => $member->assignment_id,
                    'assignment_member_id' => $member->id,
                    'template_version_id' => $templateVersion?->id,
                    'status' => 'DRAFT',
                    'current_version' => 1,
                    'content_snapshot' => $snapshot,
                    'is_demo' => false,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
                $document->assignments()->attach($member->id);
                $document->versions()->create([
                    'version' => 1,
                    'template_version_id' => $templateVersion?->id,
                    'content_snapshot' => $snapshot,
                    'created_by' => $user->id,
                ]);
                DocumentAction::create([
                    'document_id' => $document->id,
                    'actor_id' => $user->id,
                    'action' => 'DRAFT_CREATED',
                    'outcome' => 'DRAFT',
                    'occurred_at' => now(),
                ]);
                app(AuditLogger::class)->record($request, 'SPK_DRAFT_CREATED', 'Document', $document->id, null, [
                    'document_type' => 'SPK',
                    'worker_category' => $workerCategory,
                    'document_mode' => $mode,
                    'assignment_member_id' => $member->id,
                ]);
                $createdDocuments[] = $document->load(['activity', 'assignmentMember.partner', 'templateVersion']);
            }
            return $createdDocuments;
        });

        if ($created instanceof JsonResponse) return $created;
        return response()->json(['items' => collect($created)->map(fn (Document $document) => $this->resource($document))->values()], 201);
    }

    public function createBastDraft(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(['ADMIN_BPS', 'PJK', 'OPERATOR']), 403);
        $data = $request->validate([
            'spkDocumentId' => ['required', 'integer', 'exists:documents,id'],
            'documentNumber' => ['nullable', 'string', 'max:120', 'unique:documents,document_number'],
            'documentMode' => ['nullable', Rule::in(self::MODES)],
        ]);
        $spk = Document::with(['assignmentMember.partner', 'assignmentMember.assignment.activity', 'assignmentMember.workItems'])
            ->findOrFail($data['spkDocumentId']);
        if ($spk->document_type !== 'SPK' || $spk->status !== 'FINAL') {
            return response()->json(['message' => 'BAST hanya dapat dibuat dari SPK final.'], 409);
        }
        if (!$this->visibleDocuments($user)->whereKey($spk->id)->exists()) {
            return response()->json(['message' => 'SPK di luar lingkup akun.'], 403);
        }
        if (!$this->bastPrerequisitesMetForSpk($spk)) {
            return response()->json(['message' => 'Seluruh target pekerjaan terkait SPK harus memiliki laporan yang telah diterima pemeriksa sebelum BAST dibuat.'], 409);
        }

        $mode = $data['documentMode'] ?? $spk->document_mode;
        if ($mode === 'GABUNGAN' && !config('sikomit.documents.combined_mode_enabled', false)) {
            return response()->json(['message' => 'Mode dokumen gabungan belum diaktifkan oleh pejabat berwenang.'], 409);
        }
        if ($mode !== $spk->document_mode) {
            return response()->json(['message' => 'Mode BAST harus mengikuti mode SPK terkait.'], 409);
        }
        if (Document::where('document_type', 'BAST')->where('parent_document_id', $spk->id)
            ->whereNotIn('status', ['DIBATALKAN', 'DIBATALKAN_ADMIN'])->exists()) {
            return response()->json(['message' => 'BAST aktif untuk SPK ini sudah ada.'], 409);
        }

        $member = $spk->assignmentMember;
        $activity = $member->assignment->activity;
        $templateVersion = $this->approvedTemplate('BAST', $spk->worker_category, $mode);
        $acceptedItems = WorkReportItem::query()->whereIn('assignment_work_item_id', $this->requiredWorkItemIds($spk))
            ->whereHas('report', fn (Builder $report) => $report
                ->where('assignment_member_id', $member->id)
                ->where('spk_document_id', $spk->id)
                ->where('status', 'DITERIMA'))
            ->with('report')
            ->get();
        $workItems = $acceptedItems->map(fn (WorkReportItem $item) => [
            'assignmentWorkItemId' => (string) ($item->assignment_work_item_id ?? ''),
            'uraian' => $item->description,
            'target' => (float) ($item->assignmentWorkItem?->target_volume ?? 0),
            'actualVolume' => (float) $item->actual_volume,
            'satuan' => $item->unit ?? '',
            'note' => $item->note ?? '',
            'workCategory' => $item->assignmentWorkItem?->work_category ?? '',
            'documentValue' => '',
        ])->values()->all();
        $snapshot = [
            'document_number' => $data['documentNumber'] ?? '',
            'document_date' => '',
            'handover_date' => '',
            'partner_name' => $member->partner->full_name,
            'partner_code' => $member->partner->partner_code ?? '',
            'activity_name' => $activity->name,
            'activity_code' => $activity->code,
            'spk_number' => $spk->document_number ?? '',
            'work_period' => $member->assignment->starts_on->format('d/m/Y').' - '.$member->assignment->ends_on->format('d/m/Y'),
            'work_items' => $workItems,
            'verified_results' => collect($workItems)->map(fn ($item) => $item['uraian'].': '.$item['actualVolume'].' '.$item['satuan'])->implode(' | '),
            'document_status' => 'DRAF',
            'draft_banner' => 'DRAF — BELUM FINAL',
        ];

        return DB::transaction(function () use ($request, $user, $spk, $member, $activity, $templateVersion, $mode, $data, $snapshot) {
            $document = Document::create([
                'document_type' => 'BAST',
                'worker_category' => $spk->worker_category,
                'document_mode' => $mode,
                'document_number' => $data['documentNumber'] ?? null,
                'activity_id' => $activity->id,
                'assignment_id' => $member->assignment_id,
                'assignment_member_id' => $member->id,
                'parent_document_id' => $spk->id,
                'template_version_id' => $templateVersion?->id,
                'status' => 'DRAFT',
                'current_version' => 1,
                'content_snapshot' => $snapshot,
                'is_demo' => false,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $document->assignments()->attach($member->id);
            $document->versions()->create([
                'version' => 1,
                'template_version_id' => $templateVersion?->id,
                'content_snapshot' => $snapshot,
                'created_by' => $user->id,
            ]);
            DocumentAction::create([
                'document_id' => $document->id,
                'actor_id' => $user->id,
                'action' => 'DRAFT_CREATED_FROM_VERIFIED_WORK',
                'outcome' => 'DRAFT',
                'occurred_at' => now(),
            ]);
            app(AuditLogger::class)->record($request, 'BAST_DRAFT_CREATED', 'Document', $document->id, null, [
                'spk_document_id' => $spk->id,
                'assignment_member_id' => $member->id,
                'worker_category' => $spk->worker_category,
                'document_mode' => $mode,
            ]);
            return response()->json($this->resource($document->load(['activity', 'assignmentMember.partner', 'templateVersion', 'parent'])), 201);
        });
    }

    public function updateDraft(Request $request, Document $document): JsonResponse
    {
        if (!$this->visibleDocuments($request->user())->whereKey($document->id)->exists()) {
            return response()->json(['message' => 'Dokumen di luar lingkup akun.'], 403);
        }
        if (!in_array($document->status, ['DRAFT', 'PERLU_PERBAIKAN'], true)) {
            return response()->json(['message' => 'Dokumen hanya dapat diubah ketika DRAFT atau perlu perbaikan.'], 409);
        }
        if (!in_array($document->created_by, [$request->user()->id], true) && !$request->user()->hasRole('ADMIN_BPS')) {
            return response()->json(['message' => 'Hanya pembuat atau Admin BPS yang dapat mengubah draft ini.'], 403);
        }
        $data = $request->validate([
            'documentNumber' => ['nullable', 'string', 'max:120', Rule::unique('documents', 'document_number')->ignore($document->id)],
            'documentDate' => ['nullable', 'date_format:Y-m-d'],
            'handoverDate' => ['nullable', 'date_format:Y-m-d'],
            'workItemValues' => ['nullable', 'array', 'max:50'],
            'workItemValues.*.assignmentWorkItemId' => ['required', 'integer'],
            'workItemValues.*.value' => ['required', 'string', 'max:120'],
        ]);

        $before = [
            'document_number' => $document->document_number,
            'document_date' => $document->content_snapshot['document_date'] ?? '',
            'handover_date' => $document->content_snapshot['handover_date'] ?? '',
        ];
        $snapshot = $document->content_snapshot ?? [];
        $snapshot['document_number'] = trim((string) ($data['documentNumber'] ?? $document->document_number ?? ''));
        $snapshot['document_date'] = $data['documentDate'] ?? $snapshot['document_date'] ?? '';
        if (array_key_exists('handoverDate', $data)) $snapshot['handover_date'] = $data['handoverDate'];
        if (!empty($data['workItemValues'])) {
            if (!in_array('work_item_value', $document->templateVersion?->placeholder_fields ?? [], true)) {
                return response()->json(['message' => 'Nilai per rincian hanya dapat dicatat sebagai isi dokumen jika template aktif mewajibkannya.'], 422);
            }
            $providedValues = collect($data['workItemValues'])->keyBy(fn (array $row) => (string) $row['assignmentWorkItemId']);
            $knownIds = collect($snapshot['work_items'] ?? [])->pluck('assignmentWorkItemId')->map(fn ($id) => (string) $id)->all();
            if (array_diff($providedValues->keys()->all(), $knownIds) !== []) {
                return response()->json(['message' => 'Nilai dokumen hanya boleh dikaitkan dengan rincian pada penugasan ini.'], 422);
            }
            $snapshot['work_items'] = collect($snapshot['work_items'] ?? [])->map(function (array $item) use ($providedValues) {
                $provided = $providedValues->get((string) ($item['assignmentWorkItemId'] ?? ''));
                if ($provided) $item['documentValue'] = trim($provided['value']);
                return $item;
            })->all();
        }
        $document->forceFill([
            'document_number' => $snapshot['document_number'] ?: null,
            'content_snapshot' => $snapshot,
            'updated_by' => $request->user()->id,
        ])->save();
        $version = (int) $document->current_version + 1;
        $document->forceFill(['current_version' => $version])->save();
        $document->versions()->create([
            'version' => $version,
            'template_version_id' => $document->template_version_id,
            'content_snapshot' => $snapshot,
            'created_by' => $request->user()->id,
        ]);
        DocumentAction::create([
            'document_id' => $document->id,
            'actor_id' => $request->user()->id,
            'action' => 'DRAFT_UPDATED',
            'outcome' => $document->status,
            'occurred_at' => now(),
        ]);
        app(AuditLogger::class)->record($request, 'DOCUMENT_DRAFT_UPDATED', 'Document', $document->id, $before, [
            'document_number' => $document->document_number,
            'document_date' => $snapshot['document_date'] ?? '',
            'handover_date' => $snapshot['handover_date'] ?? '',
            'version' => $version,
        ]);

        return response()->json($this->resource($document->fresh()->load(['activity', 'assignmentMember.partner', 'templateVersion'])));
    }

    public function preview(Request $request, Document $document, DocumentRenderer $renderer): StreamedResponse|JsonResponse
    {
        if (!$this->visibleDocuments($request->user())->whereKey($document->id)->exists()) {
            return response()->json(['message' => 'Dokumen di luar lingkup akun.'], 403);
        }
        if ($document->status === 'FINAL' || ($document->is_demo && $document->status === 'SIMULASI_SELESAI')) {
            return $this->file($request, $document);
        }
        if (!in_array($document->status, ['DRAFT', 'PERLU_PERBAIKAN'], true)) {
            $currentVersion = $document->versions()->where('version', $document->current_version)->first();
            if (!$currentVersion?->pdf_path || !Storage::disk('local')->exists($currentVersion->pdf_path)) {
                return response()->json(['message' => 'PDF versi pemeriksaan belum tersedia.'], 404);
            }
            return Storage::disk('local')->response($currentVersion->pdf_path, null, [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        if (!$document->template_version_id) {
            return response()->json(['message' => 'Belum ada versi template resmi yang disetujui untuk dokumen ini.'], 409);
        }

        try {
            $template = $document->templateVersion;
            $snapshot = $document->content_snapshot ?? [];
            $rendered = $renderer->render($document, $template, $snapshot, $document->status !== 'FINAL');
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $version = $document->versions()->firstOrNew(['version' => $document->current_version]);
        $version->fill([
            'template_version_id' => $template->id,
            'content_snapshot' => $snapshot,
            'docx_path' => $rendered['docx_path'],
            'pdf_path' => $rendered['pdf_path'],
            'sha256' => $rendered['sha256'],
            'created_by' => $request->user()->id,
        ])->save();

        app(AuditLogger::class)->record($request, 'DOCUMENT_PREVIEW_RENDERED', 'Document', $document->id, null, [
            'version' => $version->version,
            'sha256' => $rendered['sha256'],
            'draft' => $document->status !== 'FINAL',
        ]);

        return Storage::disk('local')->response($rendered['pdf_path'], null, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function submit(Request $request, Document $document): JsonResponse
    {
        if (!$this->visibleDocuments($request->user())->whereKey($document->id)->exists()) {
            return response()->json(['message' => 'Dokumen di luar lingkup akun.'], 403);
        }
        if (!in_array($document->status, ['DRAFT', 'PERLU_PERBAIKAN'], true)) {
            return response()->json(['message' => 'Status dokumen tidak dapat dikirim untuk pemeriksaan.'], 409);
        }
        if (!$document->document_number || !$document->template_version_id || $document->templateVersion?->status !== 'APPROVED') {
            return response()->json(['message' => 'Nomor dokumen dan template versi disetujui wajib tersedia sebelum pemeriksaan.'], 409);
        }
        $currentVersion = $document->versions()->where('version', $document->current_version)->first();
        if (!$currentVersion?->pdf_path || !Storage::disk('local')->exists($currentVersion->pdf_path)) {
            return response()->json(['message' => 'Pratinjau PDF dari template yang disetujui wajib dibuat sebelum pemeriksaan.'], 409);
        }
        if ($document->document_type === 'BAST' && !$this->bastPrerequisitesMet($document)) {
            return response()->json(['message' => 'BAST harus merujuk pada SPK final dan hasil pekerjaan yang telah diterima.'], 409);
        }

        $before = $document->status;
        $document->forceFill(['status' => 'MENUNGGU_PEMERIKSAAN', 'updated_by' => $request->user()->id])->save();
        DocumentAction::create([
            'document_id' => $document->id,
            'document_version_id' => $currentVersion->id,
            'actor_id' => $request->user()->id,
            'action' => 'SUBMITTED_FOR_REVIEW',
            'outcome' => 'MENUNGGU_PEMERIKSAAN',
            'occurred_at' => now(),
        ]);
        app(AuditLogger::class)->record($request, 'DOCUMENT_SUBMITTED', 'Document', $document->id, ['status' => $before], ['status' => $document->status]);

        return response()->json($this->resource($document->fresh()->load(['activity', 'assignmentMember.partner', 'templateVersion'])));
    }

    public function review(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(['PJK', 'PEMERIKSA']), 403);
        abort_unless($this->visibleDocuments($user)->whereKey($document->id)->exists(), 403, 'Dokumen di luar lingkup akun.');
        if ($document->status !== 'MENUNGGU_PEMERIKSAAN') {
            return response()->json(['message' => 'Dokumen tidak sedang menunggu pemeriksaan.'], 409);
        }
        if ($document->created_by === $user->id || $document->updated_by === $user->id) {
            return response()->json(['message' => 'Pemeriksa harus berbeda dari pembuat/editor terakhir.'], 403);
        }
        $data = $request->validate([
            'decision' => ['required', Rule::in(['SETUJUI', 'PERLU_PERBAIKAN'])],
            'note' => ['nullable', 'string', 'max:3000', Rule::requiredIf(fn () => $request->input('decision') === 'PERLU_PERBAIKAN')],
        ]);
        $before = $document->status;
        $next = $data['decision'] === 'SETUJUI' ? 'MENUNGGU_PERSETUJUAN' : 'PERLU_PERBAIKAN';
        $document->forceFill(['status' => $next, 'updated_by' => $user->id])->save();
        DocumentAction::create([
            'document_id' => $document->id,
            'document_version_id' => $document->versions()->where('version', $document->current_version)->value('id'),
            'actor_id' => $user->id,
            'action' => 'REVIEW',
            'outcome' => $next,
            'note' => $data['note'] ?? null,
            'occurred_at' => now(),
        ]);
        app(AuditLogger::class)->record($request, 'DOCUMENT_REVIEWED', 'Document', $document->id,
            ['status' => $before], ['status' => $next, 'note' => $data['note'] ?? null]);

        return response()->json($this->resource($document->fresh()->load(['activity', 'assignmentMember.partner', 'templateVersion'])));
    }

    public function approve(Request $request, Document $document, DocumentRenderer $renderer): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(['ADMIN_BPS', 'PEJABAT_PERSETUJU']), 403);
        abort_unless($this->visibleDocuments($user)->whereKey($document->id)->exists(), 403, 'Dokumen di luar lingkup akun.');
        if ($document->status !== 'MENUNGGU_PERSETUJUAN') {
            return response()->json(['message' => 'Dokumen belum selesai diperiksa.'], 409);
        }
        if ($document->created_by === $user->id || $document->updated_by === $user->id ||
            $document->actions()->where('action', 'REVIEW')->where('actor_id', $user->id)->exists()) {
            return response()->json(['message' => 'Penyetuju harus berbeda dari pembuat/editor dan pemeriksa.'], 403);
        }
        $submittedVersion = $document->versions()->where('version', $document->current_version)->first();
        if (!$submittedVersion?->pdf_path || !Storage::disk('local')->exists($submittedVersion->pdf_path)) {
            return response()->json(['message' => 'PDF pemeriksaan tidak tersedia pada versi yang disetujui.'], 409);
        }
        $nextVersionNumber = (int) $document->current_version + 1;
        $snapshot = $document->content_snapshot ?? [];
        $snapshot['document_status'] = 'MENUNGGU_TANDATANGAN';
        $snapshot['draft_banner'] = '';
        $document->setAttribute('current_version', $nextVersionNumber);
        try {
            $rendered = $renderer->render($document, $document->templateVersion, $snapshot, false);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $document->setAttribute('current_version', $nextVersionNumber - 1);
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $newVersion = $document->versions()->create([
            'version' => $nextVersionNumber,
            'template_version_id' => $document->template_version_id,
            'content_snapshot' => $snapshot,
            'docx_path' => $rendered['docx_path'],
            'pdf_path' => $rendered['pdf_path'],
            'sha256' => $rendered['sha256'],
            'created_by' => $user->id,
        ]);
        $document->forceFill([
            'current_version' => $nextVersionNumber,
            'content_snapshot' => $snapshot,
            'status' => 'MENUNGGU_TANDATANGAN',
            'updated_by' => $user->id,
        ])->save();
        DocumentAction::create([
            'document_id' => $document->id,
            'document_version_id' => $submittedVersion->id,
            'actor_id' => $user->id,
            'action' => 'APPROVE',
            'outcome' => 'MENUNGGU_TANDATANGAN',
            'validation_metadata' => ['next_version_id' => $newVersion->id],
            'occurred_at' => now(),
        ]);
        app(AuditLogger::class)->record($request, 'DOCUMENT_APPROVED', 'Document', $document->id, ['status' => 'MENUNGGU_PERSETUJUAN'], ['status' => $document->status]);
        return response()->json($this->resource($document->fresh()->load(['activity', 'assignmentMember.partner', 'templateVersion'])));
    }

    public function sign(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->visibleDocuments($user)->whereKey($document->id)->exists(), 403, 'Dokumen di luar lingkup akun.');
        if ($document->status !== 'MENUNGGU_TANDATANGAN') {
            return response()->json(['message' => 'Dokumen belum berada pada tahap penandatanganan.'], 409);
        }
        $data = $request->validate([
            'capacity' => ['required', 'string', 'max:100'],
            'signatureMethod' => ['required', Rule::in(['MANUAL_HARD_COPY', 'TTE_PROVIDER'])],
            'signedDocument' => ['required_if:signatureMethod,MANUAL_HARD_COPY', 'file', 'mimes:pdf', 'max:'.(int) ceil(config('sikomit.documents.max_evidence_bytes', 10485760) / 1024)],
        ]);
        if ($data['signatureMethod'] === 'TTE_PROVIDER') {
            return response()->json(['message' => 'Integrasi penyedia TTE belum dikonfigurasi; sistem tidak mengklaim tanda tangan tersertifikasi.'], 409);
        }
        $requirement = collect($document->templateVersion?->required_signatures ?? [])->firstWhere('capacity', $data['capacity']);
        if (!$requirement) return response()->json(['message' => 'Kapasitas penandatangan tidak tercantum dalam template yang disetujui.'], 403);
        if (!$user->hasRole($requirement['role'] ?? '')) {
            return response()->json(['message' => 'Peran akun tidak sesuai dengan kapasitas penandatangan.'], 403);
        }
        if ($user->hasRole('PETUGAS_MITRA') && (int) $user->partner_id !== (int) $document->assignmentMember?->partner_id) {
            return response()->json(['message' => 'Mitra hanya dapat menandatangani dokumen miliknya sendiri.'], 403);
        }
        if ($document->signatures()->where('signer_capacity', $data['capacity'])
            ->whereNotIn('validation_status', ['DITOLAK'])->exists()) {
            return response()->json(['message' => 'Tanda tangan untuk kapasitas ini sudah tercatat. Revisi harus mengikuti prosedur yang sah.'], 409);
        }

        $file = $data['signedDocument'];
        $path = $file->storeAs("sikomit/documents/{$document->id}/signatures", Str::uuid().'.pdf', 'local');
        $signature = DocumentSignature::create([
            'document_id' => $document->id,
            'document_version_id' => $document->versions()->where('version', $document->current_version)->value('id'),
            'signer_user_id' => $user->id,
            'signer_capacity' => $data['capacity'],
            'signature_method' => 'MANUAL_HARD_COPY',
            'signed_artifact_path' => $path,
            'artifact_sha256' => hash_file('sha256', $file->getRealPath()),
            'validation_status' => 'MENUNGGU_VERIFIKASI',
            'signed_at' => now(),
        ]);
        DocumentAction::create([
            'document_id' => $document->id,
            'document_version_id' => $signature->document_version_id,
            'actor_id' => $user->id,
            'action' => 'SIGNATURE_RECORDED',
            'outcome' => 'MENUNGGU_VERIFIKASI',
            'signature_method' => 'MANUAL_HARD_COPY',
            'signer_capacity' => $data['capacity'],
            'signed_artifact_path' => $path,
            'signed_at' => $signature->signed_at,
            'occurred_at' => now(),
            'validation_metadata' => ['claim' => 'Bukan TTE tersertifikasi'],
        ]);
        app(AuditLogger::class)->record($request, 'DOCUMENT_SIGNATURE_RECORDED', 'Document', $document->id, null, [
            'capacity' => $data['capacity'],
            'method' => 'MANUAL_HARD_COPY',
            'signature_id' => $signature->id,
            'sha256' => $signature->artifact_sha256,
        ], $user->id);

        return response()->json([
            'signatureId' => (string) $signature->id,
            'capacity' => $signature->signer_capacity,
            'status' => $signature->validation_status,
            'message' => 'Bukti tanda tangan manual tersimpan dan menunggu pemeriksaan. Ini bukan TTE tersertifikasi.',
        ], 201);
    }

    public function validateSignature(Request $request, Document $document, DocumentSignature $signature): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->visibleDocuments($user)->whereKey($document->id)->exists(), 403, 'Dokumen di luar lingkup akun.');
        abort_unless($user->hasRole(['ADMIN_BPS', 'PJK', 'PEMERIKSA']), 403);
        if ((int) $signature->document_id !== (int) $document->id || $signature->validation_status !== 'MENUNGGU_VERIFIKASI') {
            return response()->json(['message' => 'Bukti tanda tangan tidak dapat diperiksa pada status ini.'], 409);
        }
        if ((int) $signature->signer_user_id === (int) $user->id) {
            return response()->json(['message' => 'Pemeriksa tanda tangan harus berbeda dari penandatangan.'], 403);
        }
        $data = $request->validate([
            'decision' => ['required', Rule::in(['VALID', 'DITOLAK'])],
            'note' => ['nullable', 'string', 'max:2000', Rule::requiredIf(fn () => $request->input('decision') === 'DITOLAK')],
        ]);
        $status = $data['decision'] === 'VALID' ? 'TERVERIFIKASI_MANUAL' : 'DITOLAK';
        $signature->forceFill([
            'validation_status' => $status,
            'validated_by' => $user->id,
            'validated_at' => now(),
            'validation_note' => $data['note'] ?? null,
        ])->save();
        DocumentAction::create([
            'document_id' => $document->id,
            'document_version_id' => $signature->document_version_id,
            'actor_id' => $user->id,
            'action' => 'SIGNATURE_VALIDATED',
            'outcome' => $status,
            'note' => $data['note'] ?? null,
            'signer_capacity' => $signature->signer_capacity,
            'signed_at' => $signature->signed_at,
            'occurred_at' => now(),
            'validation_metadata' => ['signature_id' => $signature->id, 'claim' => 'Validasi manual; bukan validasi kriptografis/TTE tersertifikasi'],
        ]);
        app(AuditLogger::class)->record($request, 'DOCUMENT_SIGNATURE_VALIDATED', 'Document', $document->id, null, [
            'signature_id' => $signature->id,
            'capacity' => $signature->signer_capacity,
            'status' => $status,
            'note' => $data['note'] ?? null,
        ], $user->id);

        $document->refresh();
        if ($status === 'TERVERIFIKASI_MANUAL' && $this->allRequiredSignaturesValidated($document)) {
            $document->forceFill(['status' => 'MENUNGGU_FINALISASI', 'updated_by' => $user->id])->save();
        }
        return response()->json(['ok' => true, 'signatureId' => (string) $signature->id, 'status' => $status, 'documentStatus' => $document->fresh()->status]);
    }

    public function signatureFile(Request $request, Document $document, DocumentSignature $signature): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($this->visibleDocuments($user)->whereKey($document->id)->exists(), 403, 'Dokumen di luar lingkup akun.');
        abort_unless((int) $signature->document_id === (int) $document->id, 404);
        abort_unless($user->hasRole(['ADMIN_BPS', 'PJK', 'PEMERIKSA', 'PEJABAT_PERSETUJU']) ||
            ($user->hasRole('PETUGAS_MITRA') && (int) $user->id === (int) $signature->signer_user_id), 403);
        if (!$signature->signed_artifact_path || !Storage::disk('local')->exists($signature->signed_artifact_path)) {
            return response()->json(['message' => 'Bukti tanda tangan tidak tersedia.'], 404);
        }
        return Storage::disk('local')->download($signature->signed_artifact_path, 'bukti-tanda-tangan-manual.pdf', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function finalize(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->visibleDocuments($user)->whereKey($document->id)->exists(), 403, 'Dokumen di luar lingkup akun.');
        abort_unless($user->hasRole(['ADMIN_BPS', 'PEJABAT_PERSETUJU']), 403);
        if ($document->status !== 'MENUNGGU_FINALISASI' || !$this->allRequiredSignaturesValidated($document)) {
            return response()->json(['message' => 'Dokumen belum memenuhi seluruh persyaratan tanda tangan.'], 409);
        }
        if ($document->created_by === $user->id || $document->actions()->where('action', 'APPROVE')->where('actor_id', $user->id)->exists()) {
            return response()->json(['message' => 'Finalisasi harus dilakukan oleh pejabat yang berbeda dari pembuat dan penyetuju.'], 403);
        }
        if ($document->document_type === 'BAST' && !$this->bastPrerequisitesMet($document)) {
            return response()->json(['message' => 'BAST harus merujuk pada SPK final dan seluruh target yang diterima.'], 409);
        }
        $data = $request->validate([
            'finalSignedPdf' => ['required', 'file', 'mimes:pdf', 'max:'.(int) ceil(config('sikomit.documents.max_evidence_bytes', 10485760) / 1024)],
            'confirmComplete' => ['accepted'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $file = $data['finalSignedPdf'];
        $hash = hash_file('sha256', $file->getRealPath());
        $versionNumber = (int) $document->current_version + 1;
        $path = $file->storeAs("sikomit/documents/{$document->id}/final", "v{$versionNumber}-signed.pdf", 'local');
        $previous = $document->versions()->where('version', $document->current_version)->first();

        return DB::transaction(function () use ($request, $user, $document, $data, $hash, $versionNumber, $path, $previous) {
            $snapshot = $document->content_snapshot ?? [];
            $snapshot['document_status'] = 'FINAL';
            $snapshot['draft_banner'] = '';
            $version = $document->versions()->create([
                'version' => $versionNumber,
                'template_version_id' => $document->template_version_id,
                'content_snapshot' => $snapshot,
                'docx_path' => $previous?->docx_path,
                'pdf_path' => $path,
                'sha256' => $hash,
                'created_by' => $user->id,
            ]);
            $document->forceFill([
                'current_version' => $versionNumber,
                'content_snapshot' => $snapshot,
                'status' => 'FINAL',
                'finalized_at' => now(),
                'locked_at' => now(),
                'updated_by' => $user->id,
            ])->save();
            ArchiveEntry::create([
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'archived_by' => $user->id,
                'archived_at' => now(),
                'sha256' => $hash,
            ]);
            DocumentAction::create([
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'actor_id' => $user->id,
                'action' => 'FINALIZED_AND_ARCHIVED',
                'outcome' => 'FINAL',
                'note' => $data['note'] ?? null,
                'occurred_at' => now(),
                'validation_metadata' => ['signature_type' => 'MANUAL_HARD_COPY_SCAN', 'not_certified_tte' => true],
            ]);
            app(AuditLogger::class)->record($request, 'DOCUMENT_FINALIZED_ARCHIVED', 'Document', $document->id, ['status' => 'MENUNGGU_FINALISASI'], [
                'status' => 'FINAL', 'version' => $versionNumber, 'sha256' => $hash,
                'signature_type' => 'MANUAL_HARD_COPY_SCAN', 'not_certified_tte' => true,
            ], $user->id);
            return response()->json($this->resource($document->fresh()->load(['activity', 'assignmentMember.partner', 'templateVersion', 'parent'])));
        });
    }

    public function file(Request $request, Document $document): StreamedResponse|JsonResponse
    {
        if (!$this->visibleDocuments($request->user())->whereKey($document->id)->exists()) {
            return response()->json(['message' => 'Dokumen di luar lingkup akun.'], 403);
        }
        $isDemoArchive = $document->is_demo && $document->status === 'SIMULASI_SELESAI';
        if ($document->status !== 'FINAL' && !$isDemoArchive) {
            return response()->json(['message' => 'File final hanya tersedia setelah dokumen final diarsipkan.'], 409);
        }
        $version = $document->versions()->where('version', $document->current_version)->first();
        if (!$version?->pdf_path || !Storage::disk('local')->exists($version->pdf_path)) {
            return response()->json(['message' => 'PDF final tidak tersedia di arsip privat.'], 404);
        }
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', ($isDemoArchive ? 'DEMO-' : '').($document->document_number ?: 'dokumen-final'));
        return Storage::disk('local')->download($version->pdf_path, "{$filename}.pdf", [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-SiKomit-Demo' => $isDemoArchive ? 'true' : 'false',
        ]);
    }

    private function allRequiredSignaturesValidated(Document $document): bool
    {
        $requirements = $document->templateVersion?->required_signatures ?? [];
        if ($requirements === []) return false;
        foreach ($requirements as $requirement) {
            if (!$document->signatures()
                ->where('signer_capacity', $requirement['capacity'] ?? '')
                ->where('validation_status', 'TERVERIFIKASI_MANUAL')
                ->exists()) {
                return false;
            }
        }
        return true;
    }

    private function visibleDocuments(User $user): Builder
    {
        $query = Document::query();
        if ($user->hasRole('ADMIN_BPS')) return $query;
        if ($user->hasRole('PJK')) {
            return $query->where(fn (Builder $builder) => $builder
                ->whereHas('assignment', fn (Builder $assignment) => $assignment->where('coordinator_id', $user->id))
                ->orWhereHas('activity', fn (Builder $activity) => $activity->where('responsible_user_id', $user->id)));
        }
        if ($user->hasRole('OPERATOR')) return $query->where('created_by', $user->id);
        if ($user->hasRole('PETUGAS_MITRA') && $user->partner_id) {
            return $query->whereHas('assignmentMember', fn (Builder $member) => $member->where('partner_id', $user->partner_id));
        }
        if ($user->hasRole('PEMERIKSA')) {
            return $query->where(fn (Builder $builder) => $builder
                ->where('status', 'MENUNGGU_PEMERIKSAAN')
                ->orWhereHas('signatures', fn (Builder $signature) => $signature->where('validation_status', 'MENUNGGU_VERIFIKASI')));
        }
        if ($user->hasRole('PEJABAT_PERSETUJU')) return $query->whereIn('status', ['MENUNGGU_PERSETUJUAN', 'MENUNGGU_FINALISASI', 'FINAL']);
        if ($user->hasRole('PENANDATANGAN')) return $query->whereIn('status', ['MENUNGGU_TANDATANGAN', 'MENUNGGU_FINALISASI', 'FINAL']);
        if ($user->hasRole('AUDITOR')) return $query->whereIn('status', ['FINAL', 'DIARSIPKAN']);
        abort(403, 'Tidak memiliki lingkup akses dokumen.');
    }

    private function approvedTemplate(string $type, string $category, string $mode): ?DocumentTemplateVersion
    {
        return DocumentTemplateVersion::query()->where('status', 'APPROVED')
            ->whereHas('template', fn (Builder $template) => $template
                ->where('document_type', $type)
                ->where('worker_category', $category)
                ->where('document_mode', $mode)
                ->where('is_active', true))
            ->orderByDesc('version')->first();
    }

    private function bastPrerequisitesMet(Document $document): bool
    {
        if (!$document->parent_document_id) return false;
        $spk = Document::find($document->parent_document_id);
        if (!$spk || $spk->document_type !== 'SPK' || $spk->status !== 'FINAL') return false;
        return $this->bastPrerequisitesMetForSpk($spk);
    }

    private function bastPrerequisitesMetForSpk(Document $spk): bool
    {
        if (!$spk->assignment_member_id) return false;
        $requiredIds = $this->requiredWorkItemIds($spk);
        if ($requiredIds === []) return false;
        $acceptedIds = WorkReportItem::query()->whereIn('assignment_work_item_id', $requiredIds)
            ->whereHas('report', fn (Builder $report) => $report
                ->where('assignment_member_id', $spk->assignment_member_id)
                ->where('spk_document_id', $spk->id)
                ->where('status', 'DITERIMA'))
            ->distinct()->pluck('assignment_work_item_id')->map(fn ($id) => (int) $id)->all();
        return array_diff($requiredIds, $acceptedIds) === [];
    }

    private function requiredWorkItemIds(Document $spk): array
    {
        $query = \App\Models\AssignmentWorkItem::query()
            ->whereHas('member', fn (Builder $member) => $member->where('id', $spk->assignment_member_id));
        if ($spk->document_mode === 'TERPISAH' && $spk->worker_category !== 'PENDATAAN_DAN_PENGOLAHAN') {
            $query->where('work_category', $spk->worker_category);
        }
        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function resource(Document $document): array
    {
        return [
            'id' => (string) $document->id,
            'documentType' => $document->document_type,
            'workerCategory' => $document->worker_category,
            'documentMode' => $document->document_mode,
            'documentNumber' => $document->document_number ?? '',
            'activityId' => $document->activity_id ? (string) $document->activity_id : '',
            'activityName' => $document->activity?->name ?? '',
            'assignmentId' => $document->assignment_id ? (string) $document->assignment_id : '',
            'assignmentMemberId' => $document->assignment_member_id ? (string) $document->assignment_member_id : '',
            'partnerId' => $document->assignmentMember?->partner_id ? (string) $document->assignmentMember->partner_id : '',
            'partnerName' => $document->assignmentMember?->partner?->full_name ?? '',
            'parentDocumentId' => $document->parent_document_id ? (string) $document->parent_document_id : '',
            'status' => $document->status,
            'version' => $document->current_version,
            'templateApproved' => $document->templateVersion?->status === 'APPROVED',
            'snapshot' => $document->content_snapshot ?? [],
            'demo' => (bool) $document->is_demo,
            'createdAt' => $document->created_at?->getTimestampMs() ?? 0,
            'finalizedAt' => $document->finalized_at?->getTimestampMs(),
        ];
    }
}
