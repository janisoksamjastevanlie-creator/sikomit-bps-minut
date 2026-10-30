<?php

namespace Tests\Feature\Api\V2;

use App\Models\Role;
use App\Models\Document;
use App\Models\DocumentTemplateVersion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Services\DocumentRenderer;
use Tests\TestCase;
use ZipArchive;

class DocumentTemplateWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_template_upload_stays_draft_until_a_different_admin_approves_it(): void
    {
        $uploader = $this->userWithRole('templateop', 'OPERATOR');
        $reviewer = $this->userWithRole('templadmin', 'ADMIN_BPS');
        $response = $this->actingAs($uploader, 'sanctum')->post('/api/v2/templates', [
            'documentType' => 'SPK',
            'workerCategory' => 'PENGOLAHAN',
            'documentMode' => 'TERPISAH',
            'name' => 'Template SPK Pengolahan Uji',
            'file' => $this->docxWithPlaceholders(),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('placeholderFields.0', 'document_number');
        $versionId = $response->json('versionId');

        $this->actingAs($uploader, 'sanctum')->postJson("/api/v2/templates/{$versionId}/approve")->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/v2/templates/{$versionId}/approve", [
            'requiredSignatures' => [
                ['capacity' => 'PIHAK_PERTAMA', 'role' => 'PENANDATANGAN'],
                ['capacity' => 'PIHAK_KEDUA', 'role' => 'PETUGAS_MITRA'],
            ],
        ])
            ->assertOk()->assertJsonPath('status', 'APPROVED');

        $this->assertDatabaseHas('document_template_versions', ['id' => $versionId, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('document_templates', ['id' => $response->json('templateId'), 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'DOCUMENT_TEMPLATE_APPROVED', 'subject_id' => (string) $versionId]);

        $version = DocumentTemplateVersion::findOrFail($versionId);
        $document = Document::create([
            'document_type' => 'SPK',
            'worker_category' => 'PENGOLAHAN',
            'document_mode' => 'TERPISAH',
            'status' => 'DRAFT',
            'template_version_id' => $version->id,
            'content_snapshot' => [],
            'created_by' => $reviewer->id,
            'is_demo' => false,
        ]);
        $rendered = app(DocumentRenderer::class)->render($document, $version, [
            'document_number' => 'DRAFT/01',
            'document_date' => '30 Oktober 2026',
            'partner_name' => 'Mitra Uji',
            'activity_name' => 'Kegiatan Uji',
            'document_status' => '',
            'draft_banner' => 'DRAF — BELUM FINAL',
            'work_period' => 'November 2026',
            'work_items' => [[
                'uraian' => 'Pengolahan', 'target' => 10, 'satuan' => 'Dokumen',
                'jadwalMulai' => '2026-11-01', 'jadwalSelesai' => '2026-11-30',
                'documentValue' => 'Nilai dokumen uji',
            ]],
        ], true);
        $this->assertTrue(Storage::disk('local')->exists($rendered['docx_path']));
        $this->assertTrue(Storage::disk('local')->exists($rendered['pdf_path']));
        $this->assertSame('%PDF', substr(Storage::disk('local')->get($rendered['pdf_path']), 0, 4));
    }

    public function test_filled_example_without_required_placeholders_cannot_be_approved(): void
    {
        $uploader = $this->userWithRole('templateop2', 'OPERATOR');
        $reviewer = $this->userWithRole('templadmin2', 'ADMIN_BPS');
        $response = $this->actingAs($uploader, 'sanctum')->post('/api/v2/templates', [
            'documentType' => 'BAST',
            'workerCategory' => 'PENGOLAHAN',
            'documentMode' => 'TERPISAH',
            'name' => 'Contoh BAST Terisi',
            'file' => $this->docxWithPlaceholders(false),
        ], ['Accept' => 'application/json'])->assertCreated();
        $versionId = $response->json('versionId');
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/v2/templates/{$versionId}/approve", [
            'requiredSignatures' => [['capacity' => 'PIHAK_PERTAMA', 'role' => 'PENANDATANGAN']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Placeholder template wajib belum tersedia: document_number, document_date, partner_name, activity_name, document_status, draft_banner, spk_number, handover_date, verified_results, work_item_description, work_item_actual, work_item_unit, work_item_schedule, work_item_result.');
    }

    public function test_combined_document_template_cannot_be_uploaded_while_feature_is_disabled(): void
    {
        $admin = $this->userWithRole('templateadmin3', 'ADMIN_BPS');
        $this->actingAs($admin, 'sanctum')->post('/api/v2/templates', [
            'documentType' => 'SPK',
            'workerCategory' => 'PENDATAAN_DAN_PENGOLAHAN',
            'documentMode' => 'GABUNGAN',
            'name' => 'Template Gabungan Draft',
            'file' => $this->docxWithPlaceholders(),
        ], ['Accept' => 'application/json'])->assertStatus(409);
    }

    private function userWithRole(string $username, string $roleCode): User
    {
        $user = User::factory()->create(['username' => $username, 'is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());
        return $user;
    }

    private function docxWithPlaceholders(bool $complete = true): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sikomit-template-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $fields = $complete
            ? '${document_number} ${document_date} ${partner_name} ${activity_name} ${document_status} ${draft_banner} ${work_period}'
            : 'Contoh nama dan nomor terisi';
        $tableFields = $complete
            ? ['work_item_description', 'work_item_target', 'work_item_actual', 'work_item_unit', 'work_item_schedule', 'work_item_result', 'work_item_value']
            : [];
        $row = $tableFields === [] ? '' : '<w:tbl><w:tr>'.implode('', array_map(fn ($field) => '<w:tc><w:p><w:r><w:t>${'.$field.'}</w:t></w:r></w:p></w:tc>', $tableFields)).'</w:tr></w:tbl>';
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.$fields.'</w:t></w:r></w:p>'.$row.'<w:sectPr/></w:body></w:document>');
        $zip->close();

        return new UploadedFile($path, 'template.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }
}
