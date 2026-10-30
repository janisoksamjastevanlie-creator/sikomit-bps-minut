<?php

namespace Tests\Feature\Api\V2;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoArchiveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_explicitly_demo_archived_pdf_is_downloadable_with_demo_warning_header(): void
    {
        $admin = $this->userWithRole('demoarchiveadmin', 'ADMIN_BPS');
        $document = $this->demoDocument($admin, true);

        $this->actingAs($admin, 'sanctum')->get("/api/v2/documents/{$document->id}/file")
            ->assertOk()
            ->assertHeader('X-SiKomit-Demo', 'true')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertDatabaseMissing('document_signatures', ['document_id' => $document->id]);
    }

    public function test_simulation_status_is_not_downloadable_for_non_demo_document(): void
    {
        $admin = $this->userWithRole('demoblockadmin', 'ADMIN_BPS');
        $document = $this->demoDocument($admin, false);

        $this->actingAs($admin, 'sanctum')->getJson("/api/v2/documents/{$document->id}/file")
            ->assertStatus(409);
    }

    public function test_demo_walkthrough_page_is_visible_only_to_admin_bps(): void
    {
        $admin = $this->userWithRole('demopageadmin', 'ADMIN_BPS');
        $operator = $this->userWithRole('demopageoperator', 'OPERATOR');

        $this->actingAs($admin)->get('/demo-alur')->assertOk()
            ->assertSee('Demo End-to-End')
            ->assertSee('BUKAN FINAL RESMI');
        $this->actingAs($operator)->get('/demo-alur')->assertForbidden();
    }

    private function demoDocument(User $admin, bool $isDemo): Document
    {
        $document = Document::create([
            'document_type' => 'SPK',
            'worker_category' => 'PENDATAAN_LAPANGAN',
            'document_mode' => 'TERPISAH',
            'document_number' => 'DEMO-ARCHIVE-'.($isDemo ? 'YES' : 'NO').'-'.$admin->id,
            'status' => 'SIMULASI_SELESAI',
            'current_version' => 1,
            'content_snapshot' => ['simulation_notice' => 'DEMO'],
            'is_demo' => $isDemo,
            'created_by' => $admin->id,
        ]);
        $path = "sikomit/demo-archives/{$document->document_number}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.4\nDEMO — BUKAN DOKUMEN RESMI\n");
        DocumentVersion::create([
            'document_id' => $document->id,
            'version' => 1,
            'content_snapshot' => $document->content_snapshot,
            'pdf_path' => $path,
            'sha256' => hash('sha256', Storage::disk('local')->get($path)),
            'created_by' => $admin->id,
        ]);
        return $document;
    }

    private function userWithRole(string $username, string $roleCode): User
    {
        $user = User::factory()->create(['username' => $username, 'is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());
        return $user;
    }
}
