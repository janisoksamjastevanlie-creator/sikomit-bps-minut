<?php

namespace Tests\Feature\Api\V2;

use App\Models\Activity;
use App\Models\Assignment;
use App\Models\AssignmentMember;
use App\Models\AssignmentWorkItem;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Partner;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DocumentSigningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_spk_draft_review_manual_signatures_and_final_archive_work_without_claiming_certified_tte(): void
    {
        $maker = $this->userWithRole('docmaker', 'ADMIN_BPS');
        $approver = $this->userWithRole('docapprover', 'PEJABAT_PERSETUJU');
        $finalizer = $this->userWithRole('docfinalizer', 'ADMIN_BPS');
        $reviewer = $this->userWithRole('docreviewer', 'PJK');
        $officialSigner = $this->userWithRole('docsigner', 'PENANDATANGAN');
        $partner = Partner::create([
            'full_name' => 'Mitra Tanda Tangan', 'partner_type' => 'PERORANGAN',
            'verification_status' => 'TERVERIFIKASI', 'is_active' => true, 'is_demo' => false,
        ]);
        $partnerUser = $this->userWithRole('docpartner', 'PETUGAS_MITRA', ['partner_id' => $partner->id]);
        $activity = Activity::create([
            'code' => 'SPK-2026', 'name' => 'Kegiatan SPK', 'year' => 2026,
            'responsible_user_id' => $reviewer->id, 'responsible_name' => 'PJK Uji',
            'target_volume' => 5, 'unit' => 'dokumen', 'partner_need' => 1,
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
            'status' => 'AKTIF', 'is_demo' => false, 'created_by' => $maker->id,
        ]);
        $assignment = Assignment::create([
            'activity_id' => $activity->id, 'description' => 'Pengolahan data',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'DITUGASKAN',
            'coordinator_id' => $reviewer->id, 'is_demo' => false, 'created_by' => $maker->id,
        ]);
        $member = AssignmentMember::create([
            'assignment_id' => $assignment->id, 'partner_id' => $partner->id,
            'status' => 'DITUGASKAN', 'contract_category' => 'PENGOLAHAN', 'created_by' => $maker->id,
        ]);
        $workItem = AssignmentWorkItem::create([
            'assignment_member_id' => $member->id, 'description' => 'Pengolahan Survei Uji',
            'target_volume' => 5, 'unit' => 'dokumen', 'work_category' => 'PENGOLAHAN',
        ]);

        $template = DocumentTemplate::create([
            'document_type' => 'SPK', 'worker_category' => 'PENGOLAHAN', 'document_mode' => 'TERPISAH',
            'name' => 'Template SPK Uji', 'is_active' => true, 'created_by' => $maker->id,
        ]);
        $templateVersion = DocumentTemplateVersion::create([
            'document_template_id' => $template->id, 'version' => 1,
            'original_filename' => 'template.docx', 'storage_path' => 'sikomit/templates/test/source.docx',
            'sha256' => str_repeat('a', 64), 'placeholder_fields' => [
                'document_number', 'document_date', 'partner_name', 'activity_name',
                'document_status', 'draft_banner', 'work_period', 'work_item_description',
                'work_item_target', 'work_item_unit', 'work_item_schedule', 'work_item_value',
            ],
            'required_signatures' => [
                ['capacity' => 'PIHAK_PERTAMA', 'role' => 'PENANDATANGAN'],
                ['capacity' => 'PIHAK_KEDUA', 'role' => 'PETUGAS_MITRA'],
            ],
            'status' => 'APPROVED', 'uploaded_by' => $maker->id,
            'approved_by' => $approver->id, 'approved_at' => now(),
        ]);
        Storage::disk('local')->put($templateVersion->storage_path, $this->docxTemplate());

        $draft = $this->actingAs($maker, 'sanctum')->postJson('/api/v2/spk/drafts', [
            'assignmentMemberId' => $member->id, 'documentMode' => 'TERPISAH',
        ])->assertCreated()->json('items.0');
        $documentId = $draft['id'];

        $this->actingAs($maker, 'sanctum')->putJson("/api/v2/documents/{$documentId}/draft", [
            'documentNumber' => 'SPK-LOCAL-001', 'documentDate' => '2026-10-30',
            'workItemValues' => [['assignmentWorkItemId' => $workItem->id, 'value' => 'Nilai uji sesuai template']],
        ])->assertOk();
        $this->actingAs($maker, 'sanctum')->get("/api/v2/documents/{$documentId}/preview")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($maker, 'sanctum')->postJson("/api/v2/documents/{$documentId}/submit")->assertOk()->assertJsonPath('status', 'MENUNGGU_PEMERIKSAAN');
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/v2/documents/{$documentId}/review", ['decision' => 'SETUJUI'])->assertOk()->assertJsonPath('status', 'MENUNGGU_PERSETUJUAN');
        $this->actingAs($approver, 'sanctum')->postJson("/api/v2/documents/{$documentId}/approve")->assertOk()->assertJsonPath('status', 'MENUNGGU_TANDATANGAN');

        foreach ([[$officialSigner, 'PIHAK_PERTAMA'], [$partnerUser, 'PIHAK_KEDUA']] as [$signer, $capacity]) {
            $signed = $this->actingAs($signer, 'sanctum')->post("/api/v2/documents/{$documentId}/signatures", [
                'capacity' => $capacity,
                'signatureMethod' => 'MANUAL_HARD_COPY',
                'signedDocument' => UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\n% Signed copy\n"),
            ], ['Accept' => 'application/json'])->assertCreated();
            $signatureId = $signed->json('signatureId');
            $this->actingAs($reviewer, 'sanctum')->postJson("/api/v2/documents/{$documentId}/signatures/{$signatureId}/validate", [
                'decision' => 'VALID',
            ])->assertOk();
        }

        $this->actingAs($finalizer, 'sanctum')->post('/api/v2/documents/'.$documentId.'/finalize', [
            'finalSignedPdf' => UploadedFile::fake()->createWithContent('final-signed.pdf', "%PDF-1.4\n% Final manual signed copy\n"),
            'confirmComplete' => '1',
            'note' => 'Salinan fisik bertanda tangan telah diperiksa.',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'FINAL');

        $this->actingAs($finalizer, 'sanctum')->get("/api/v2/documents/{$documentId}/file")
            ->assertOk()->assertHeader('cache-control', 'no-store, private');

        $this->assertDatabaseHas('documents', ['id' => $documentId, 'status' => 'FINAL']);
        $this->assertDatabaseHas('archive_entries', ['document_id' => $documentId]);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $documentId,
            'action' => 'FINALIZED_AND_ARCHIVED',
        ]);

        $workReport = $this->actingAs($partnerUser, 'sanctum')->postJson('/api/v2/work-reports', [
            'assignmentMemberId' => $member->id,
            'spkDocumentId' => $documentId,
            'summary' => 'Hasil pekerjaan telah diserahkan untuk pemeriksaan.',
            'items' => [[
                'assignmentWorkItemId' => $workItem->id,
                'actualVolume' => 5,
                'note' => 'Selesai sesuai target.',
            ]],
        ])->assertCreated()->json();
        $workReportId = $workReport['id'];
        $this->actingAs($partnerUser, 'sanctum')->postJson("/api/v2/work-reports/{$workReportId}/submit")
            ->assertOk()->assertJsonPath('status', 'DIAJUKAN');
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/v2/work-reports/{$workReportId}/review", [
            'decision' => 'DITERIMA',
        ])->assertOk()->assertJsonPath('status', 'DITERIMA');

        $bastTemplate = DocumentTemplate::create([
            'document_type' => 'BAST', 'worker_category' => 'PENGOLAHAN', 'document_mode' => 'TERPISAH',
            'name' => 'Template BAST Uji', 'is_active' => true, 'created_by' => $maker->id,
        ]);
        $bastTemplateVersion = DocumentTemplateVersion::create([
            'document_template_id' => $bastTemplate->id, 'version' => 1,
            'original_filename' => 'template-bast.docx', 'storage_path' => 'sikomit/templates/test/bast.docx',
            'sha256' => str_repeat('b', 64),
            'placeholder_fields' => [
                'document_number', 'document_date', 'partner_name', 'activity_name',
                'document_status', 'draft_banner', 'spk_number', 'handover_date',
                'work_period', 'verified_results', 'work_item_description', 'work_item_actual',
                'work_item_unit', 'work_item_schedule', 'work_item_result',
            ],
            'required_signatures' => [
                ['capacity' => 'PIHAK_PERTAMA', 'role' => 'PENANDATANGAN'],
                ['capacity' => 'PIHAK_KEDUA', 'role' => 'PETUGAS_MITRA'],
            ],
            'status' => 'APPROVED', 'uploaded_by' => $maker->id,
            'approved_by' => $approver->id, 'approved_at' => now(),
        ]);
        Storage::disk('local')->put($bastTemplateVersion->storage_path, $this->docxTemplate(true));

        $bast = $this->actingAs($maker, 'sanctum')->postJson('/api/v2/bast/drafts', [
            'spkDocumentId' => $documentId,
        ])->assertCreated()->json();
        $bastId = $bast['id'];
        $this->actingAs($maker, 'sanctum')->putJson("/api/v2/documents/{$bastId}/draft", [
            'documentNumber' => 'BAST-LOCAL-001',
            'documentDate' => '2026-11-30',
            'handoverDate' => '2026-11-30',
        ])->assertOk();
        $this->actingAs($maker, 'sanctum')->get("/api/v2/documents/{$bastId}/preview")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($maker, 'sanctum')->postJson("/api/v2/documents/{$bastId}/submit")
            ->assertOk()->assertJsonPath('status', 'MENUNGGU_PEMERIKSAAN');
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/v2/documents/{$bastId}/review", ['decision' => 'SETUJUI'])
            ->assertOk()->assertJsonPath('status', 'MENUNGGU_PERSETUJUAN');
        $this->actingAs($approver, 'sanctum')->postJson("/api/v2/documents/{$bastId}/approve")
            ->assertOk()->assertJsonPath('status', 'MENUNGGU_TANDATANGAN');

        foreach ([[$officialSigner, 'PIHAK_PERTAMA'], [$partnerUser, 'PIHAK_KEDUA']] as [$signer, $capacity]) {
            $signed = $this->actingAs($signer, 'sanctum')->post("/api/v2/documents/{$bastId}/signatures", [
                'capacity' => $capacity,
                'signatureMethod' => 'MANUAL_HARD_COPY',
                'signedDocument' => UploadedFile::fake()->createWithContent('signed-bast.pdf', "%PDF-1.4\n% Signed BAST\n"),
            ], ['Accept' => 'application/json'])->assertCreated();
            $this->actingAs($reviewer, 'sanctum')->postJson("/api/v2/documents/{$bastId}/signatures/{$signed->json('signatureId')}/validate", [
                'decision' => 'VALID',
            ])->assertOk();
        }
        $this->actingAs($finalizer, 'sanctum')->post("/api/v2/documents/{$bastId}/finalize", [
            'finalSignedPdf' => UploadedFile::fake()->createWithContent('final-bast.pdf', "%PDF-1.4\n% Final signed BAST\n"),
            'confirmComplete' => '1',
            'note' => 'Bukti serah-terima diperiksa.',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'FINAL');
        $this->assertDatabaseHas('documents', ['id' => $bastId, 'document_type' => 'BAST', 'status' => 'FINAL']);
        $this->assertDatabaseHas('archive_entries', ['document_id' => $bastId]);
    }

    private function userWithRole(string $username, string $roleCode, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['username' => $username, 'is_active' => true], $attributes));
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());
        return $user;
    }

    private function docxTemplate(bool $forBast = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sikomit-spk-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $fields = '${document_number} ${document_date} ${partner_name} ${activity_name} ${document_status} ${draft_banner} ${work_period}';
        if ($forBast) $fields .= ' ${spk_number} ${handover_date} ${verified_results}';
        $rowFields = $forBast
            ? ['work_item_description', 'work_item_actual', 'work_item_unit', 'work_item_schedule', 'work_item_result']
            : ['work_item_description', 'work_item_target', 'work_item_unit', 'work_item_schedule', 'work_item_value'];
        $row = '<w:tbl><w:tr>'.implode('', array_map(fn ($field) => '<w:tc><w:p><w:r><w:t>${'.$field.'}</w:t></w:r></w:p></w:tc>', $rowFields)).'</w:tr></w:tbl>';
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.$fields.'</w:t></w:r></w:p>'.$row.'<w:sectPr/></w:body></w:document>');
        $zip->close();
        $contents = file_get_contents($path);
        @unlink($path);
        return $contents;
    }
}
