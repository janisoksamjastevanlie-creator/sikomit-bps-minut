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
use App\Services\ExcelTemplateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExcelImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_template_download_is_an_xlsx_workbook(): void
    {
        $admin = $this->userWithRole('xlsxtemplateadmin', 'ADMIN_BPS');
        $response = $this->actingAs($admin, 'sanctum')->get('/api/v2/excel-import/template')
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    public function test_import_screen_is_available_to_authorized_web_accounts(): void
    {
        $admin = $this->userWithRole('xlsxscreenadmin', 'ADMIN_BPS');
        $this->actingAs($admin)->get('/impor-excel')->assertOk()
            ->assertSee('Import Excel')
            ->assertSee('Kegiatan beserta target');
    }

    public function test_generated_workbook_has_all_blank_import_sheets(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sikomit-template-');
        try {
            app(ExcelTemplateService::class)->writeTo($path);
            $book = IOFactory::load($path);
            $this->assertSame(['Petunjuk', 'Mitra', 'Kegiatan', 'Penugasan', 'Laporan'], $book->getSheetNames());
            $this->assertSame('kodeMitra', $book->getSheetByName('Mitra')->getCell('A1')->getValue());
            $this->assertSame('kodeLaporan', $book->getSheetByName('Laporan')->getCell('A1')->getValue());
            $this->assertNull($book->getSheetByName('Mitra')->getCell('A2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    public function test_partner_import_creates_pending_verification_and_is_atomic(): void
    {
        $admin = $this->userWithRole('xlsxpartneradmin', 'ADMIN_BPS');
        $headers = ['kodeMitra','nama','jenis','sobatId','telepon','email','alamat','kecamatan','desa','provinsi'];
        $file = $this->xlsx('Mitra', $headers, [
            ['XLS-M-001','Mitra XLS Uji','PERORANGAN','','','uji@example.invalid','','Kecamatan Uji','','Sulawesi Utara'],
            ['XLS-M-001','Mitra Duplikat','PERORANGAN','','','','','','',''],
        ]);

        $this->actingAs($admin, 'sanctum')->post('/api/v2/excel-import/import', [
            'type' => 'mitra', 'file' => $file,
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertDatabaseMissing('partners', ['partner_code' => 'XLS-M-001']);

        $success = $this->xlsx('Mitra', $headers, [
            ['XLS-M-002','Mitra XLS Uji','PERORANGAN','','','uji@example.invalid','','Kecamatan Uji','','Sulawesi Utara'],
        ]);
        $this->actingAs($admin, 'sanctum')->post('/api/v2/excel-import/import', [
            'type' => 'mitra', 'file' => $success,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('created', 1);
        $this->assertDatabaseHas('partners', [
            'partner_code' => 'XLS-M-002', 'verification_status' => 'MENUNGGU_VERIFIKASI', 'is_demo' => false,
        ]);
    }

    public function test_activity_import_creates_draft_with_separate_targets(): void
    {
        $admin = $this->userWithRole('xlsxactivityadmin', 'ADMIN_BPS');
        $headers = ['kodeKegiatan','namaKegiatan','tahun','periode','penanggungJawab','jenisKegiatan','uraian','wilayah','tanggalMulai','tanggalSelesai','kebutuhanMitra','persyaratan','uraianTarget','volumeTarget','satuanTarget','wilayahTarget','mulaiTarget','selesaiTarget'];
        $file = $this->xlsx('Kegiatan', $headers, [
            ['XLS-A-001','Kegiatan XLS','2027','Januari','PJK Uji','Survei','Uji import','Wilayah Uji','2027-01-01','2027-01-31','1','','Pendataan wilayah',5,'wilayah','','',''],
            ['XLS-A-001','Kegiatan XLS','2027','Januari','PJK Uji','Survei','Uji import','Wilayah Uji','2027-01-01','2027-01-31','1','','Pemeriksaan hasil',10,'dokumen','','',''],
        ]);
        $this->actingAs($admin, 'sanctum')->post('/api/v2/excel-import/import', [
            'type' => 'kegiatan', 'file' => $file,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('created', 1);

        $activity = Activity::where('code', 'XLS-A-001')->firstOrFail();
        $this->assertSame('DRAFT', $activity->status);
        $this->assertCount(2, $activity->targets);
        $this->assertFalse($activity->is_demo);
    }

    public function test_assignment_import_requires_existing_active_activity_and_verified_partner(): void
    {
        $admin = $this->userWithRole('xlsxassignmentadmin', 'ADMIN_BPS');
        $activity = Activity::create([
            'code' => 'XLS-ACTIVE-1', 'name' => 'Kegiatan Aktif', 'year' => 2027,
            'responsible_name' => 'PJK Uji', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31',
            'target_volume' => 5, 'partner_need' => 1, 'status' => 'AKTIF', 'is_demo' => false,
            'created_by' => $admin->id,
        ]);
        $partner = Partner::create([
            'partner_code' => 'XLS-VERIFIED-1', 'full_name' => 'Mitra Terverifikasi',
            'partner_type' => 'PERORANGAN', 'verification_status' => 'TERVERIFIKASI',
            'is_active' => true, 'is_demo' => false,
        ]);
        $headers = ['kodePenugasan','kodeKegiatan','kodeMitra','kategoriPetugas','uraianPekerjaan','wilayah','tanggalMulai','tanggalSelesai','uraianTarget','volumeTarget','satuanTarget','kategoriTarget','wilayahTarget','mulaiTarget','selesaiTarget'];
        $file = $this->xlsx('Penugasan', $headers, [
            ['XLS-P-001',$activity->code,$partner->partner_code,'PENDATAAN_LAPANGAN','Pendataan XLS','Wilayah Uji','2027-01-02','2027-01-20','Target pendataan',5,'wilayah','PENDATAAN_LAPANGAN','','',''],
        ]);
        $this->actingAs($admin, 'sanctum')->post('/api/v2/excel-import/import', [
            'type' => 'penugasan', 'file' => $file,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('created', 1);
        $this->assertDatabaseHas('assignments', ['activity_id' => $activity->id, 'is_demo' => false]);
        $this->assertDatabaseHas('assignment_work_items', ['description' => 'Target pendataan', 'work_category' => 'PENDATAAN_LAPANGAN']);
    }

    public function test_partner_can_import_only_a_draft_report_for_their_own_final_spk(): void
    {
        $admin = $this->userWithRole('xlsxreportadmin', 'ADMIN_BPS');
        $partner = Partner::create([
            'partner_code' => 'XLS-REPORT-MITRA', 'full_name' => 'Mitra Laporan XLS',
            'partner_type' => 'PERORANGAN', 'verification_status' => 'TERVERIFIKASI', 'is_active' => true,
        ]);
        $mitra = $this->userWithRole('xlsxreportmitra', 'PETUGAS_MITRA', ['partner_id' => $partner->id]);
        $activity = Activity::create([
            'code' => 'XLS-REPORT-ACT', 'name' => 'Kegiatan Laporan', 'year' => 2027,
            'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31', 'target_volume' => 5,
            'partner_need' => 1, 'status' => 'AKTIF', 'is_demo' => false, 'created_by' => $admin->id,
        ]);
        $assignment = Assignment::create([
            'activity_id' => $activity->id, 'description' => 'Penugasan laporan',
            'starts_on' => '2027-01-02', 'ends_on' => '2027-01-20',
            'status' => 'DITUGASKAN', 'is_demo' => false, 'created_by' => $admin->id,
        ]);
        $member = AssignmentMember::create([
            'assignment_id' => $assignment->id, 'partner_id' => $partner->id,
            'status' => 'DITUGASKAN', 'contract_category' => 'PENDATAAN_LAPANGAN',
        ]);
        $workItem = AssignmentWorkItem::create([
            'assignment_member_id' => $member->id, 'description' => 'Wilayah target',
            'target_volume' => 5, 'unit' => 'wilayah', 'work_category' => 'PENDATAAN_LAPANGAN',
        ]);
        Document::create([
            'document_type' => 'SPK', 'worker_category' => 'PENDATAAN_LAPANGAN', 'document_mode' => 'TERPISAH',
            'document_number' => 'SPK-XLS-001', 'activity_id' => $activity->id,
            'assignment_id' => $assignment->id, 'assignment_member_id' => $member->id,
            'status' => 'FINAL', 'current_version' => 1, 'is_demo' => false, 'created_by' => $admin->id,
        ]);
        $headers = ['kodeLaporan','nomorSPK','ringkasan','uraianTarget','kategoriTarget','realisasi','catatan'];
        $file = $this->xlsx('Laporan', $headers, [
            ['XLS-R-001','SPK-XLS-001','Realisasi simulasi uji',$workItem->description,'PENDATAAN_LAPANGAN',4,''],
        ]);
        $this->actingAs($mitra, 'sanctum')->post('/api/v2/excel-import/import', [
            'type' => 'laporan', 'file' => $file,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('created', 1);
        $this->assertDatabaseHas('work_reports', [
            'assignment_member_id' => $member->id, 'spk_document_id' => Document::where('document_number', 'SPK-XLS-001')->value('id'),
            'status' => 'DRAFT', 'submitted_at' => null,
        ]);
    }

    private function xlsx(string $sheetName, array $headers, array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($sheetName);
        $sheet->fromArray([$headers, ...$rows], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'sikomit-import-');
        (new Xlsx($book))->save($path);
        $contents = file_get_contents($path);
        @unlink($path);
        $book->disconnectWorksheets();
        return UploadedFile::fake()->createWithContent('import.xlsx', $contents);
    }

    private function userWithRole(string $username, string $roleCode, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['username' => $username, 'is_active' => true], $attributes));
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());
        return $user;
    }
}
