<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_web_dashboard_requires_authentication_and_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Masuk ke SiKomit')->assertSee('data-password-toggle', false);
    }

    public function test_web_admin_login_opens_dashboard_and_password_rotation_page(): void
    {
        $user = User::factory()->create([
            'username' => 'webadmin',
            'password' => Hash::make('SecureWebPass123!'),
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $user->roles()->attach(Role::where('code', 'ADMIN_BPS')->firstOrFail());

        $this->post('/login', [
            'username' => 'webadmin',
            'password' => 'SecureWebPass123!',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertOk()->assertSee('Password awal wajib diganti');
        $passwordPage = $this->get('/password/change')->assertOk();
        $this->assertSame(3, substr_count($passwordPage->getContent(), 'data-password-toggle'));
        $this->post('/password/change', [
            'current_password' => 'SecureWebPass123!',
            'password' => 'SecureWebPass456!',
            'password_confirmation' => 'SecureWebPass456!',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertOk()->assertSee('Dashboard');
    }

    public function test_same_origin_web_session_can_call_the_authorized_api(): void
    {
        $user = User::factory()->create([
            'username' => 'weboperator',
            'password' => Hash::make('SecureWebPass123!'),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::where('code', 'OPERATOR')->firstOrFail());
        $this->post('/login', ['username' => 'weboperator', 'password' => 'SecureWebPass123!'])->assertRedirect('/dashboard');

        $this->getJson('/api/v2/mitra', [
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/mitra',
        ])->assertOk()->assertJsonStructure(['items', 'total', 'limit', 'offset']);
    }

    public function test_authorized_user_can_open_document_console(): void
    {
        $user = User::factory()->create([
            'username' => 'webdocadmin',
            'password' => Hash::make('SecureWebPass123!'),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::where('code', 'ADMIN_BPS')->firstOrFail());
        $this->post('/login', ['username' => 'webdocadmin', 'password' => 'SecureWebPass123!'])->assertRedirect('/dashboard');

        $this->get('/dokumen')->assertOk()
            ->assertSee('SPK & BAST')
            ->assertSee('Buat SPK')
            ->assertSee('template DOCX placeholder yang sudah disahkan');
    }

    public function test_signer_can_login_and_open_scoped_document_console(): void
    {
        $user = User::factory()->create([
            'username' => 'websigner',
            'password' => Hash::make('SecureWebPass123!'),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::where('code', 'PENANDATANGAN')->firstOrFail());
        $this->post('/login', ['username' => 'websigner', 'password' => 'SecureWebPass123!'])->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertOk()->assertSee('Dokumen SPK & BAST', false);
        $this->get('/dokumen')->assertOk()->assertSee('SPK & BAST');
    }
}
