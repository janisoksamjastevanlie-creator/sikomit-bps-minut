<?php

namespace Tests\Feature\Api\V2;

use App\Models\Activity;
use App\Models\Assignment;
use App\Models\AssignmentMember;
use App\Models\ArchiveEntry;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Partner;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dashboard_counts_are_database_backed_and_financial_free(): void
    {
        $admin = $this->userWithRole('dashadmin', 'ADMIN_BPS');
        $partner = Partner::create([
            'full_name' => 'Mitra Dashboard', 'partner_type' => 'PERORANGAN',
            'verification_status' => 'TERVERIFIKASI', 'is_active' => true, 'is_demo' => false,
        ]);
        $activity = Activity::create([
            'code' => 'DASH-2026', 'name' => 'Kegiatan Dashboard', 'year' => 2026,
            'target_volume' => 10, 'partner_need' => 1, 'status' => 'AKTIF', 'is_demo' => false,
            'created_by' => $admin->id,
        ]);
        $assignment = Assignment::create([
            'activity_id' => $activity->id, 'description' => 'Pendataan',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-10',
            'status' => 'DALAM_PELAKSANAAN', 'is_demo' => false, 'created_by' => $admin->id,
        ]);
        AssignmentMember::create([
            'assignment_id' => $assignment->id, 'partner_id' => $partner->id,
            'status' => 'DALAM_PELAKSANAAN', 'contract_category' => 'PENDATAAN_LAPANGAN',
        ]);
        $demoDocument = Document::create([
            'document_type' => 'BAST', 'worker_category' => 'PENDATAAN_LAPANGAN',
            'document_number' => 'DEMO-BAST-DASH', 'activity_id' => $activity->id,
            'assignment_id' => $assignment->id, 'status' => 'FINAL', 'current_version' => 1,
            'content_snapshot' => ['simulation_notice' => 'DEMO'], 'is_demo' => true,
            'created_by' => $admin->id,
        ]);
        $demoVersion = DocumentVersion::create([
            'document_id' => $demoDocument->id, 'version' => 1,
            'content_snapshot' => ['simulation_notice' => 'DEMO'], 'created_by' => $admin->id,
        ]);
        ArchiveEntry::create([
            'document_id' => $demoDocument->id, 'document_version_id' => $demoVersion->id,
            'archived_by' => $admin->id, 'archived_at' => now(), 'sha256' => str_repeat('d', 64),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v2/dashboard/administrasi')->assertOk();
        $response->assertJsonPath('mitra.total', 1)
            ->assertJsonPath('kegiatan.aktif', 1)
            ->assertJsonPath('penugasan.dalamPelaksanaan', 1)
            ->assertJsonPath('bast.final', 0)
            ->assertJsonPath('arsip.total', 0)
            ->assertJsonMissingPath('honor')
            ->assertJsonMissingPath('pembayaran');
        $this->assertStringNotContainsString('999999', $response->getContent());
    }

    private function userWithRole(string $username, string $roleCode): User
    {
        $user = User::factory()->create(['username' => $username, 'is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());
        return $user;
    }
}
