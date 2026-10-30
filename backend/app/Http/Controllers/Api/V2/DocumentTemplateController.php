<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class DocumentTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = DocumentTemplate::query()
            ->with(['versions' => fn ($query) => $query->orderByDesc('version')])
            ->orderBy('document_type')->orderBy('worker_category')->get();

        return response()->json(['items' => $templates->map(fn (DocumentTemplate $template) => [
            'id' => (string) $template->id,
            'documentType' => $template->document_type,
            'workerCategory' => $template->worker_category,
            'documentMode' => $template->document_mode,
            'name' => $template->name,
            'isActive' => (bool) $template->is_active,
            'versions' => $template->versions->map(fn (DocumentTemplateVersion $version) => [
                'id' => (string) $version->id,
                'version' => $version->version,
                'status' => $version->status,
                'placeholderFields' => $version->placeholder_fields ?? [],
                'requiredSignatures' => $version->required_signatures ?? [],
                'uploadedById' => (string) ($version->uploaded_by ?? ''),
                'canApprove' => $request->user()->hasRole('ADMIN_BPS')
                    && $version->status === 'DRAFT'
                    && (int) $version->uploaded_by !== (int) $request->user()->id,
                'uploadedAt' => $version->created_at?->getTimestampMs() ?? 0,
                'approvedAt' => $version->approved_at?->getTimestampMs(),
            ])->values(),
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'documentType' => ['required', Rule::in(['SPK', 'BAST'])],
            'workerCategory' => ['required', Rule::in(['PENDATAAN_LAPANGAN', 'PENGOLAHAN', 'PENDATAAN_DAN_PENGOLAHAN'])],
            'documentMode' => ['required', Rule::in(['TERPISAH', 'GABUNGAN'])],
            'name' => ['required', 'string', 'max:180'],
            'file' => ['required', 'file', 'extensions:docx', 'max:'.(int) ceil(config('sikomit.documents.max_template_bytes', 15728640) / 1024)],
        ]);

        if ($data['documentMode'] === 'GABUNGAN' && !config('sikomit.documents.combined_mode_enabled', false)) {
            return response()->json(['message' => 'Mode dokumen gabungan belum diaktifkan oleh pejabat berwenang.'], 409);
        }

        $file = $data['file'];
        $zip = new ZipArchive();
        $opened = $zip->open($file->getRealPath());
        if ($opened !== true || $zip->locateName('word/document.xml') === false ||
            $zip->locateName('[Content_Types].xml') === false || $zip->locateName('_rels/.rels') === false) {
            if ($opened === true) $zip->close();
            return response()->json(['message' => 'Berkas bukan dokumen DOCX Word yang valid.'], 422);
        }
        $documentXml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();
        preg_match_all('/\$\{([A-Za-z][A-Za-z0-9_.]*)\}/', html_entity_decode(strip_tags($documentXml)), $matches);
        $placeholderFields = array_values(array_unique($matches[1] ?? []));

        return DB::transaction(function () use ($request, $data, $file, $placeholderFields) {
            $template = DocumentTemplate::firstOrCreate([
                'document_type' => $data['documentType'],
                'worker_category' => $data['workerCategory'],
                'document_mode' => $data['documentMode'],
                'name' => trim($data['name']),
            ], [
                'is_active' => false,
                'created_by' => $request->user()->id,
            ]);
            $versionNumber = ((int) $template->versions()->max('version')) + 1;
            $path = $file->storeAs(
                "sikomit/templates/{$template->id}/v{$versionNumber}",
                'source.docx',
                'local'
            );
            $version = $template->versions()->create([
                'version' => $versionNumber,
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'placeholder_fields' => $placeholderFields,
                'validation_findings' => $placeholderFields === [] ? ['Tidak ditemukan placeholder ${field}; contoh terisi tidak siap sebagai template dinamis.'] : [],
                'status' => 'DRAFT',
                'uploaded_by' => $request->user()->id,
            ]);
            app(AuditLogger::class)->record($request, 'DOCUMENT_TEMPLATE_UPLOADED', 'DocumentTemplateVersion', $version->id, null, [
                'document_type' => $data['documentType'],
                'worker_category' => $data['workerCategory'],
                'document_mode' => $data['documentMode'],
                'version' => $versionNumber,
                'placeholder_count' => count($placeholderFields),
                'sha256' => $version->sha256,
            ]);

            return response()->json([
                'templateId' => (string) $template->id,
                'versionId' => (string) $version->id,
                'version' => $version->version,
                'status' => $version->status,
                'placeholderFields' => $version->placeholder_fields,
                'validationFindings' => $version->validation_findings,
            ], 201);
        });
    }

    public function file(DocumentTemplateVersion $version): StreamedResponse|JsonResponse
    {
        if (!Storage::disk('local')->exists($version->storage_path)) {
            return response()->json(['message' => 'File template tidak tersedia.'], 404);
        }
        return Storage::disk('local')->download($version->storage_path, $version->original_filename);
    }

    public function approve(Request $request, DocumentTemplateVersion $version): JsonResponse
    {
        $data = $request->validate([
            'requiredSignatures' => ['required', 'array', 'min:1', 'max:5'],
            'requiredSignatures.*.capacity' => ['required', 'string', 'max:100'],
            'requiredSignatures.*.role' => ['required', Rule::in(['PEJABAT_PERSETUJU', 'PENANDATANGAN', 'PETUGAS_MITRA'])],
        ]);
        $user = $request->user();
        if ($version->uploaded_by === $user->id) {
            return response()->json(['message' => 'Template harus diperiksa oleh pengguna yang berbeda dari pengunggah.'], 403);
        }
        if ($version->status !== 'DRAFT') {
            return response()->json(['message' => 'Hanya versi template DRAFT yang dapat diperiksa.'], 409);
        }

        $required = $this->requiredPlaceholders($version->template);
        $missing = array_values(array_diff($required, $version->placeholder_fields ?? []));
        if ($missing !== []) {
            return response()->json(['message' => 'Placeholder template wajib belum tersedia: '.implode(', ', $missing).'.'], 422);
        }
        if ($version->template->document_mode === 'GABUNGAN' && !config('sikomit.documents.combined_mode_enabled', false)) {
            return response()->json(['message' => 'Mode dokumen gabungan belum diaktifkan oleh pejabat berwenang.'], 409);
        }

        return DB::transaction(function () use ($request, $version, $data) {
            $template = $version->template()->lockForUpdate()->firstOrFail();
            $template->versions()->where('status', 'APPROVED')->update(['status' => 'RETIRED']);
            $version->forceFill([
                'status' => 'APPROVED',
                'required_signatures' => $data['requiredSignatures'],
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ])->save();
            $template->forceFill(['is_active' => true])->save();
            app(AuditLogger::class)->record($request, 'DOCUMENT_TEMPLATE_APPROVED', 'DocumentTemplateVersion', $version->id, ['status' => 'DRAFT'], [
                'status' => 'APPROVED',
                'required_signatures' => $data['requiredSignatures'],
            ], $request->user()->id);
            return response()->json(['ok' => true, 'versionId' => (string) $version->id, 'status' => $version->status]);
        });
    }

    private function requiredPlaceholders(DocumentTemplate $template): array
    {
        $common = ['document_number', 'document_date', 'partner_name', 'activity_name', 'document_status', 'draft_banner'];
        if ($template->document_type === 'BAST') {
            return [...$common, 'spk_number', 'handover_date', 'verified_results', 'work_item_description', 'work_item_actual', 'work_item_unit', 'work_item_schedule', 'work_item_result'];
        }
        return [...$common, 'work_period', 'work_item_description', 'work_item_target', 'work_item_unit', 'work_item_schedule', 'work_item_value'];
    }
}
