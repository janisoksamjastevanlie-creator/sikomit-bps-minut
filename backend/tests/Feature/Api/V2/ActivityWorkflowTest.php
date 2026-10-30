<?php

namespace Tests\Feature\Api\V2;

use App\Models\Activity;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_creates_draft_and_activates_activity_without_financial_fields(): void
    {
        $admin = $this->userWithRole('activityadmin', 'ADMIN_BPS');
        $payload = [
            'kode' => 'K-TEST-2026',
            'nama' => 'Survei Lapangan Uji',
            'tahunAnggaran' => '2026',
            'periode' => 'November',
            'penanggungJawab' => 'PJK Uji',
            'jenisKegiatan' => 'Pendataan',
            'uraian' => 'Pendataan wilayah uji',
            'wilayah' => 'Minahasa Utara',
            'targetVolume' => 25,
            'satuan' => 'dokumen',
            'tglMulai' => '2026-11-01',
            'tglSelesai' => '2026-11-30',
            'persyaratanAdministrasi' => ['Surat pernyataan'],
            'kebutuhanMitra' => 2,
            'targetPekerjaan' => [['uraian' => 'Pendataan', 'target' => 25, 'satuan' => 'dokumen']],
            'honor' => 900000,
            'tarif' => 40000,
        ];

        $created = $this->actingAs($admin, 'sanctum')->postJson('/api/v2/activities', $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonMissingPath('honor')
            ->assertJsonMissingPath('tarif');
        $id = $created->json('id');
        $this->assertDatabaseHas('activities', ['id' => $id, 'status' => 'DRAFT', 'is_demo' => false]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v2/activities/{$id}/activate")
            ->assertOk()
            ->assertJsonPath('status', 'AKTIF');
    }

    public function test_operator_sees_only_activities_created_in_their_scope(): void
    {
        $operator = $this->userWithRole('operatoract', 'OPERATOR');
        $other = $this->userWithRole('operatorother', 'OPERATOR');
        Activity::create([
            'code' => 'OWN-2026', 'name' => 'Kegiatan Operator', 'year' => 2026,
            'target_volume' => 1, 'partner_need' => 1, 'status' => 'DRAFT',
            'is_demo' => false, 'created_by' => $operator->id,
        ]);
        Activity::create([
            'code' => 'OTHER-2026', 'name' => 'Kegiatan Lain', 'year' => 2026,
            'target_volume' => 1, 'partner_need' => 1, 'status' => 'DRAFT',
            'is_demo' => false, 'created_by' => $other->id,
        ]);

        $response = $this->actingAs($operator, 'sanctum')->getJson('/api/v2/activities')->assertOk();
        $this->assertSame(['OWN-2026'], collect($response->json('items'))->pluck('kode')->all());
    }

    private function userWithRole(string $username, string $roleCode): User
    {
        $user = User::factory()->create([
            'username' => $username,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());
        return $user;
    }
}
