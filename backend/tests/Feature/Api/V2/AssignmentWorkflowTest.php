<?php

namespace Tests\Feature\Api\V2;

use App\Models\Activity;
use App\Models\Partner;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_combined_assignment_keeps_pendataan_and_processing_targets_separate(): void
    {
        $admin = $this->userWithRole('assignadmin', 'ADMIN_BPS');
        $activity = Activity::create([
            'code' => 'COMB-2026', 'name' => 'Kegiatan Gabungan', 'year' => 2026,
            'responsible_name' => 'PJK Uji', 'target_volume' => 10, 'unit' => 'dokumen',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'partner_need' => 2,
            'status' => 'AKTIF', 'is_demo' => false, 'created_by' => $admin->id,
        ]);
        $partners = collect(['Mitra Satu', 'Mitra Dua'])->map(fn (string $name) => Partner::create([
            'full_name' => $name, 'partner_type' => 'PERORANGAN',
            'verification_status' => 'TERVERIFIKASI', 'is_active' => true, 'is_demo' => false,
            'created_by' => $admin->id,
        ]));

        $payload = [
            'kegiatanId' => $activity->id,
            'mitraIds' => $partners->pluck('id')->all(),
            'contractCategory' => 'PENDATAAN_DAN_PENGOLAHAN',
            'uraian' => 'Pendataan dan pengolahan',
            'jadwalMulai' => '2026-11-02',
            'jadwalSelesai' => '2026-11-20',
            'targetPekerjaan' => [
                ['uraian' => 'Pemetaan lapangan', 'target' => 5, 'satuan' => 'wilayah', 'workCategory' => 'PENDATAAN_LAPANGAN'],
                ['uraian' => 'Pengolahan hasil', 'target' => 10, 'satuan' => 'dokumen', 'workCategory' => 'PENGOLAHAN'],
            ],
            'honor' => 999999,
        ];

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v2/assignments', $payload)
            ->assertCreated()
            ->assertJsonMissingPath('honor')
            ->assertJsonCount(2, 'members');
        $this->assertNotEmpty($response->json('members.0.assignmentMemberId'));
        $this->assertNotEmpty($response->json('members.0.targetPekerjaan.0.assignmentWorkItemId'));
        $assignmentId = $response->json('id');
        $this->assertDatabaseHas('assignment_members', [
            'assignment_id' => $assignmentId,
            'contract_category' => 'PENDATAAN_DAN_PENGOLAHAN',
        ]);
        $this->assertDatabaseHas('assignment_work_items', [
            'assignment_member_id' => $response->json('members.0.assignmentMemberId'),
            'work_category' => 'PENGOLAHAN',
            'description' => 'Pengolahan hasil',
        ]);
    }

    public function test_overlapping_active_assignments_for_the_same_partner_are_rejected(): void
    {
        $admin = $this->userWithRole('assignadmin2', 'ADMIN_BPS');
        $activity = Activity::create([
            'code' => 'OVER-2026', 'name' => 'Kegiatan Konflik', 'year' => 2026,
            'responsible_name' => 'PJK Uji', 'target_volume' => 10, 'unit' => 'dokumen',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'partner_need' => 1,
            'status' => 'AKTIF', 'is_demo' => false, 'created_by' => $admin->id,
        ]);
        $partner = Partner::create([
            'full_name' => 'Mitra Jadwal', 'partner_type' => 'PERORANGAN',
            'verification_status' => 'TERVERIFIKASI', 'is_active' => true, 'is_demo' => false,
            'created_by' => $admin->id,
        ]);
        $body = [
            'kegiatanId' => $activity->id,
            'mitraId' => $partner->id,
            'contractCategory' => 'PENGOLAHAN',
            'uraian' => 'Pengolahan data',
            'jadwalMulai' => '2026-11-02',
            'jadwalSelesai' => '2026-11-10',
            'targetPekerjaan' => [['uraian' => 'Pengolahan', 'target' => 10, 'satuan' => 'dokumen']],
        ];

        $this->actingAs($admin, 'sanctum')->postJson('/api/v2/assignments', $body)->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v2/assignments', [
            ...$body,
            'jadwalMulai' => '2026-11-10',
            'jadwalSelesai' => '2026-11-15',
        ])->assertStatus(409);
    }

    private function userWithRole(string $username, string $roleCode): User
    {
        $user = User::factory()->create(['username' => $username, 'is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());
        return $user;
    }
}
