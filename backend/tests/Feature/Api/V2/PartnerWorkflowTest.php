<?php

namespace Tests\Feature\Api\V2;

use App\Models\Partner;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_create_partner_but_must_not_verify_own_record(): void
    {
        $admin = $this->userWithRole('adminbps', 'ADMIN_BPS');
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v2/mitra', [
            'nama' => 'Mitra Uji',
            'sobatId' => 'TEST-001',
            'jenis' => 'PERORANGAN',
            'wa' => '081234567890',
            'honor' => 999999,
            'bank' => 'Tidak diterima',
        ]);

        $response->assertCreated()
            ->assertJsonPath('statusVerifikasi', 'MENUNGGU_VERIFIKASI')
            ->assertJsonPath('demo', false)
            ->assertJsonMissingPath('honor')
            ->assertJsonMissingPath('bank');

        $partner = Partner::firstOrFail();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v2/mitra/{$partner->id}/verification", [
            'status' => 'TERVERIFIKASI',
            'catatan' => '',
        ])->assertForbidden();
    }

    public function test_different_examiner_can_verify_and_status_history_is_kept(): void
    {
        $maker = $this->userWithRole('operator1', 'OPERATOR');
        $reviewer = $this->userWithRole('reviewer1', 'PEMERIKSA');
        $partner = Partner::create([
            'full_name' => 'Mitra Review',
            'partner_type' => 'PERORANGAN',
            'verification_status' => 'MENUNGGU_VERIFIKASI',
            'is_active' => true,
            'is_demo' => false,
            'created_by' => $maker->id,
        ]);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/v2/mitra/{$partner->id}/verification", [
                'status' => 'PERLU_PERBAIKAN',
                'catatan' => 'Lengkapi dokumen administrasi.',
            ])
            ->assertOk()
            ->assertJsonPath('statusVerifikasi', 'PERLU_PERBAIKAN');

        $this->assertDatabaseHas('partner_verification_histories', [
            'partner_id' => $partner->id,
            'reviewer_id' => $reviewer->id,
            'status' => 'PERLU_PERBAIKAN',
        ]);
    }

    public function test_partner_account_cannot_read_another_partner_profile(): void
    {
        $own = Partner::create(['full_name' => 'Mitra Sendiri', 'partner_type' => 'PERORANGAN']);
        $other = Partner::create(['full_name' => 'Mitra Lain', 'partner_type' => 'PERORANGAN']);
        $partnerUser = $this->userWithRole('mitrauser', 'PETUGAS_MITRA', ['partner_id' => $own->id]);

        $this->actingAs($partnerUser, 'sanctum')->getJson("/api/v2/mitra/{$own->id}")->assertOk();
        $this->actingAs($partnerUser, 'sanctum')->getJson("/api/v2/mitra/{$other->id}")->assertForbidden();
    }

    private function userWithRole(string $username, string $roleCode, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'username' => $username,
            'is_active' => true,
        ], $attributes));
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());
        return $user;
    }
}
