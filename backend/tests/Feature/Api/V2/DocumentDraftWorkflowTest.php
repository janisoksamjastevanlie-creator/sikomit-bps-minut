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
use App\Models\WorkReport;
use App\Models\WorkReportItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentDraftWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_combined_assignment_in_separate_mode_creates_two_draft_spks_without_generating_numbers(): void
    {
        $admin = $this->userWithRole('docadmin', 'ADMIN_BPS');
        $member = $this->combinedAssignmentMember($admin);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v2/spk/drafts', [
            'assignmentMemberId' => $member->id,
            'documentMode' => 'TERPISAH',
        ])->assertCreated();

        $items = $response->json('items');
        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(['PENDATAAN_LAPANGAN', 'PENGOLAHAN'], array_column($items, 'workerCategory'));
        $this->assertSame(['DRAFT', 'DRAFT'], array_column($items, 'status'));
        $this->assertSame('', $items[0]['documentNumber']);
        $this->assertFalse($items[0]['templateApproved']);
        $this->assertStringNotContainsString('honor', json_encode($items));
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_combined_mode_is_rejected_until_authorized_configuration_enables_it(): void
    {
        $admin = $this->userWithRole('docadmin2', 'ADMIN_BPS');
        $member = $this->combinedAssignmentMember($admin);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v2/spk/drafts', [
            'assignmentMemberId' => $member->id,
            'documentMode' => 'GABUNGAN',
        ])->assertStatus(409)->assertJsonPath('message', 'Mode dokumen gabungan belum diaktifkan oleh pejabat berwenang.');
    }

    public function test_bast_draft_requires_final_spk_and_accepted_work_items(): void
    {
        $admin = $this->userWithRole('bastdraftadmin', 'ADMIN_BPS');
        $member = $this->combinedAssignmentMember($admin);
        $pendataanItem = $member->workItems()->where('work_category', 'PENDATAAN_LAPANGAN')->firstOrFail();
        $spk = Document::create([
            'document_type' => 'SPK',
            'worker_category' => 'PENDATAAN_LAPANGAN',
            'document_mode' => 'TERPISAH',
            'document_number' => 'SPK-DRAFT-TEST-1',
            'activity_id' => $member->assignment->activity_id,
            'assignment_id' => $member->assignment_id,
            'assignment_member_id' => $member->id,
            'status' => 'FINAL',
            'current_version' => 1,
            'is_demo' => false,
            'created_by' => $admin->id,
        ]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v2/bast/drafts', [
            'spkDocumentId' => $spk->id,
        ])->assertStatus(409);
        $report = WorkReport::create([
            'assignment_member_id' => $member->id,
            'spk_document_id' => $spk->id,
            'status' => 'DITERIMA',
            'summary' => 'Hasil telah diperiksa',
            'accepted_by' => $admin->id,
            'accepted_at' => now(),
        ]);
        WorkReportItem::create([
            'work_report_id' => $report->id,
            'assignment_work_item_id' => $pendataanItem->id,
            'description' => $pendataanItem->description,
            'actual_volume' => 5,
            'unit' => $pendataanItem->unit,
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v2/bast/drafts', [
            'spkDocumentId' => $spk->id,
        ])->assertCreated()
            ->assertJsonPath('documentType', 'BAST')
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('parentDocumentId', (string) $spk->id);
    }

    private function combinedAssignmentMember(User $admin): AssignmentMember
    {
        $partner = Partner::create([
            'full_name' => 'Mitra Dokumen Uji', 'partner_type' => 'PERORANGAN',
            'verification_status' => 'TERVERIFIKASI', 'is_active' => true, 'is_demo' => false,
            'created_by' => $admin->id,
        ]);
        $activity = Activity::create([
            'code' => 'DOC-2026', 'name' => 'Kegiatan Dokumen', 'year' => 2026,
            'target_volume' => 10, 'partner_need' => 1,
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
            'status' => 'AKTIF', 'is_demo' => false, 'created_by' => $admin->id,
        ]);
        $assignment = Assignment::create([
            'activity_id' => $activity->id, 'description' => 'Pendataan dan pengolahan',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'DITUGASKAN',
            'is_demo' => false, 'created_by' => $admin->id,
        ]);
        $member = AssignmentMember::create([
            'assignment_id' => $assignment->id, 'partner_id' => $partner->id,
            'status' => 'DITUGASKAN', 'contract_category' => 'PENDATAAN_DAN_PENGOLAHAN',
            'created_by' => $admin->id,
        ]);
        AssignmentWorkItem::create([
            'assignment_member_id' => $member->id, 'description' => 'Pemetaan wilayah',
            'target_volume' => 5, 'unit' => 'wilayah', 'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-15', 'work_category' => 'PENDATAAN_LAPANGAN',
        ]);
        AssignmentWorkItem::create([
            'assignment_member_id' => $member->id, 'description' => 'Pengolahan hasil',
            'target_volume' => 10, 'unit' => 'dokumen', 'starts_on' => '2026-11-16',
            'ends_on' => '2026-11-30', 'work_category' => 'PENGOLAHAN',
        ]);

        return $member;
    }

    private function userWithRole(string $username, string $roleCode): User
    {
        $user = User::factory()->create(['username' => $username, 'is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());
        return $user;
    }
}
