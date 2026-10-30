<?php

namespace Tests\Feature\Api\V2;

use App\Models\Activity;
use App\Models\Assignment;
use App\Models\AssignmentMember;
use App\Models\AssignmentWorkItem;
use App\Models\Document;
use App\Models\Partner;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_report_requires_manual_evidence_review_before_acceptance(): void
    {
        $pjk = $this->userWithRole('reportpjk', 'PJK');
        $mitra = Partner::create([
            'full_name' => 'Mitra Pelaporan', 'partner_type' => 'PERORANGAN',
            'verification_status' => 'TERVERIFIKASI', 'is_active' => true, 'is_demo' => false,
        ]);
        $petugas = $this->userWithRole('reportmitra', 'PETUGAS_MITRA', ['partner_id' => $mitra->id]);
        $activity = Activity::create([
            'code' => 'WR-2026', 'name' => 'Kegiatan Pelaporan', 'year' => 2026,
            'responsible_user_id' => $pjk->id, 'target_volume' => 10,
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'partner_need' => 1,
            'status' => 'AKTIF', 'is_demo' => false, 'created_by' => $pjk->id,
        ]);
        $assignment = Assignment::create([
            'activity_id' => $activity->id, 'description' => 'Pendataan',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'DALAM_PELAKSANAAN',
            'coordinator_id' => $pjk->id, 'is_demo' => false, 'created_by' => $pjk->id,
        ]);
        $member = AssignmentMember::create([
            'assignment_id' => $assignment->id, 'partner_id' => $mitra->id,
            'status' => 'DALAM_PELAKSANAAN', 'contract_category' => 'PENDATAAN_LAPANGAN',
        ]);
        $workItem = AssignmentWorkItem::create([
            'assignment_member_id' => $member->id, 'description' => 'Pemetaan', 'target_volume' => 10,
            'unit' => 'wilayah', 'work_category' => 'PENDATAAN_LAPANGAN',
        ]);
        $spk = Document::create([
            'document_type' => 'SPK', 'worker_category' => 'PENDATAAN_LAPANGAN', 'document_mode' => 'TERPISAH',
            'document_number' => 'SPK-LOCAL-1', 'activity_id' => $activity->id, 'assignment_id' => $assignment->id,
            'assignment_member_id' => $member->id, 'status' => 'FINAL', 'current_version' => 1,
            'is_demo' => false, 'created_by' => $pjk->id,
        ]);

        $created = $this->actingAs($petugas, 'sanctum')->postJson('/api/v2/work-reports', [
            'assignmentMemberId' => $member->id,
            'spkDocumentId' => $spk->id,
            'summary' => 'Hasil pekerjaan uji',
            'items' => [[
                'assignmentWorkItemId' => $workItem->id,
                'actualVolume' => 10,
                'note' => 'Selesai',
            ]],
        ])->assertCreated()->assertJsonPath('status', 'DRAFT');
        $reportId = $created->json('id');

        $evidenceResponse = $this->actingAs($petugas, 'sanctum')->postJson("/api/v2/work-reports/{$reportId}/evidence", [
            'file' => UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\n% Test PDF\n"),
        ])->assertCreated()->assertJsonPath('scanStatus', 'PENDING_REVIEW');
        $evidenceId = $evidenceResponse->json('id');
        $this->actingAs($petugas, 'sanctum')->postJson("/api/v2/work-reports/{$reportId}/submit")->assertOk()->assertJsonPath('status', 'DIAJUKAN');

        $this->actingAs($pjk, 'sanctum')->postJson("/api/v2/work-reports/{$reportId}/review", [
            'decision' => 'DITERIMA', 'note' => '',
        ])->assertStatus(409);

        $this->actingAs($pjk, 'sanctum')->postJson("/api/v2/work-reports/{$reportId}/evidence/{$evidenceId}/review", [
            'decision' => 'CLEAN_MANUALLY_REVIEWED',
            'note' => 'File diperiksa manual pada lingkungan uji.',
        ])->assertOk()->assertJsonPath('scanStatus', 'CLEAN_MANUALLY_REVIEWED');
        $this->actingAs($pjk, 'sanctum')->postJson("/api/v2/work-reports/{$reportId}/review", [
            'decision' => 'DITERIMA', 'note' => '',
        ])->assertOk()->assertJsonPath('status', 'DITERIMA');
        $this->assertDatabaseHas('work_reports', ['id' => $reportId, 'status' => 'DITERIMA']);
    }

    private function userWithRole(string $username, string $roleCode, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['username' => $username, 'is_active' => true], $attributes));
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());
        return $user;
    }
}
