<?php

namespace Tests\Feature\Api\V2;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_login_issues_a_sanctum_token_and_returns_only_public_identity_fields(): void
    {
        $user = User::factory()->create([
            'username' => 'adminbps',
            'password' => Hash::make('SecureLocalPass123!'),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::where('code', 'ADMIN_BPS')->firstOrFail());

        $response = $this->postJson('/api/v2/auth/login', [
            'username' => 'ADMINBPS',
            'password' => 'SecureLocalPass123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.username', 'adminbps')
            ->assertJsonPath('user.role', 'ADMIN_BPS')
            ->assertJsonMissingPath('user.password');
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'sikomit-android',
        ]);
    }

    public function test_disabled_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'username' => 'disabled1',
            'password' => Hash::make('SecureLocalPass123!'),
            'is_active' => false,
        ]);
        $user->roles()->attach(Role::where('code', 'ADMIN_BPS')->firstOrFail());

        $this->postJson('/api/v2/auth/login', [
            'username' => 'disabled1',
            'password' => 'SecureLocalPass123!',
        ])->assertUnauthorized();
    }
}
