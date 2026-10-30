<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentTemplateVersion;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

class DocumentRenderer
{
    /**
     * Render only a reviewed template version. All files remain on Laravel's private local disk.
     * The snapshot is document content only; no payment operation or calculation is performed.
     *
     * @return array{docx_path:string,pdf_path:string,sha256:string}
     */
    public function render(Document $document, DocumentTemplateVersion $templateVersion, array $snapshot, bool $draft): array
    {
        if ($templateVersion->status !== 'APPROVED' || !$templateVersion->template?->is_active) {
            throw new InvalidArgumentException('Template belum disetujui dan aktif.');
        }

        $requiredFields = $templateVersion->placeholder_fields ?? [];
        $values = array_merge($snapshot, [
            'document_status' => $draft ? 'DRAF' : '',
            'draft_banner' => $draft ? 'DRAF — BELUM FINAL' : '',
        ]);
        $missing = array_values(array_filter($requiredFields, fn (string $field) =>
            !str_starts_with($field, 'work_item_') && !array_key_exists($field, $values)
        ));
        if ($missing !== []) {
            throw new InvalidArgumentException('Data dokumen belum lengkap untuk placeholder: '.implode(', ', $missing).'.');
        }

        $workItems = $snapshot['work_items'] ?? [];
        if (in_array('work_item_description', $requiredFields, true)) {
            if (!is_array($workItems) || $workItems === []) {
                throw new InvalidArgumentException('Rincian target/hasil pekerjaan belum tersedia untuk tabel template.');
            }
            if (in_array('work_item_value', $requiredFields, true) && collect($workItems)->contains(fn ($item) => trim((string) ($item['documentValue'] ?? '')) === '')) {
                throw new InvalidArgumentException('Nilai yang diwajibkan template harus diisi per rincian dokumen; nilai tidak dihitung oleh sistem.');
            }
        }

        $disk = Storage::disk('local');
        if (!$disk->exists($templateVersion->storage_path)) {
            throw new RuntimeException('File template privat tidak ditemukan.');
        }

        $versionNumber = max(1, (int) $document->current_version);
        $directory = "sikomit/documents/{$document->id}/v{$versionNumber}";
        $disk->makeDirectory($directory);
        $docxRelative = "{$directory}/document.docx";
        $pdfRelative = "{$directory}/document.pdf";
        $processor = new TemplateProcessor($disk->path($templateVersion->storage_path));

        if (in_array('work_item_description', $requiredFields, true)) {
            $rows = collect($workItems)->map(function (array $item) use ($requiredFields) {
                $source = [
                    'description' => $item['uraian'] ?? '',
                    'target' => $item['target'] ?? '',
                    'actual' => $item['actualVolume'] ?? '',
                    'unit' => $item['satuan'] ?? '',
                    'schedule' => trim(implode(' - ', array_filter([$item['jadwalMulai'] ?? '', $item['jadwalSelesai'] ?? '']))),
                    'result' => $item['note'] ?? $item['result'] ?? '',
                    'value' => $item['documentValue'] ?? '',
                    'category' => $item['workCategory'] ?? '',
                    'territory' => $item['wilayah'] ?? '',
                ];
                $row = [];
                foreach ($requiredFields as $field) {
                    if (str_starts_with($field, 'work_item_')) {
                        $column = substr($field, strlen('work_item_'));
                        $row[$field] = (string) ($source[$column] ?? '');
                    }
                }
                return $row;
            })->all();
            $processor->cloneRowAndSetValues('work_item_description', $rows);
        }

        foreach ($requiredFields as $field) {
            if (str_starts_with($field, 'work_item_')) continue;
            $value = $values[$field] ?? '';
            if (is_array($value)) {
                $value = collect($value)->map(function ($item) {
                    if (is_array($item)) return implode(' · ', array_filter(array_map('strval', $item)));
                    return (string) $item;
                })->implode(' | ');
            }
            $processor->setValue($field, (string) $value);
        }

        $processor->saveAs($disk->path($docxRelative));

        if (!Settings::setPdfRendererName(Settings::PDF_RENDERER_DOMPDF) ||
            !Settings::setPdfRendererPath(base_path('vendor/dompdf/dompdf'))) {
            throw new RuntimeException('PDF renderer Dompdf tidak tersedia.');
        }
        $phpWord = IOFactory::load($disk->path($docxRelative), 'Word2007');
        IOFactory::createWriter($phpWord, 'PDF')->save($disk->path($pdfRelative));

        return [
            'docx_path' => $docxRelative,
            'pdf_path' => $pdfRelative,
            'sha256' => hash_file('sha256', $disk->path($pdfRelative)),
        ];
    }
}
