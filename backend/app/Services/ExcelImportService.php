<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Assignment;
use App\Models\AssignmentMember;
use App\Models\Document;
use App\Models\Partner;
use App\Models\User;
use App\Models\WorkReport;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class ExcelImportService
{
    private const SHEETS = [
        'mitra' => ['name' => 'Mitra', 'headers' => ['kodeMitra', 'nama', 'jenis', 'sobatId', 'telepon', 'email', 'alamat', 'kecamatan', 'desa', 'provinsi']],
        'kegiatan' => ['name' => 'Kegiatan', 'headers' => ['kodeKegiatan', 'namaKegiatan', 'tahun', 'periode', 'penanggungJawab', 'jenisKegiatan', 'uraian', 'wilayah', 'tanggalMulai', 'tanggalSelesai', 'kebutuhanMitra', 'persyaratan', 'uraianTarget', 'volumeTarget', 'satuanTarget', 'wilayahTarget', 'mulaiTarget', 'selesaiTarget']],
        'penugasan' => ['name' => 'Penugasan', 'headers' => ['kodePenugasan', 'kodeKegiatan', 'kodeMitra', 'kategoriPetugas', 'uraianPekerjaan', 'wilayah', 'tanggalMulai', 'tanggalSelesai', 'uraianTarget', 'volumeTarget', 'satuanTarget', 'kategoriTarget', 'wilayahTarget', 'mulaiTarget', 'selesaiTarget']],
        'laporan' => ['name' => 'Laporan', 'headers' => ['kodeLaporan', 'nomorSPK', 'ringkasan', 'uraianTarget', 'kategoriTarget', 'realisasi', 'catatan']],
    ];

    public function import(UploadedFile $file, string $type, User $user, Request $request): array
    {
        $definition = self::SHEETS[$type] ?? null;
        if (!$definition) throw ValidationException::withMessages(['type' => 'Jenis import tidak dikenal.']);

        try {
            if (IOFactory::identify($file->getRealPath()) !== 'Xlsx') {
                throw ValidationException::withMessages(['file' => 'File harus berupa Excel XLSX yang valid.']);
            }
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(false);
            $book = $reader->load($file->getRealPath());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'File Excel tidak dapat dibaca. Unduh template terbaru lalu coba kembali.']);
        }

        try {
            $rows = $this->readRows($book, $definition['name'], $definition['headers']);
        } finally {
            $book->disconnectWorksheets();
        }
        if ($rows === []) throw ValidationException::withMessages(['file' => 'Sheet tidak berisi baris data.']);

        return match ($type) {
            'mitra' => $this->importPartners($rows, $user, $request),
            'kegiatan' => $this->importActivities($rows, $user, $request),
            'penugasan' => $this->importAssignments($rows, $user, $request),
            'laporan' => $this->importReports($rows, $user, $request),
        };
    }

    private function readRows($book, string $sheetName, array $expectedHeaders): array
    {
        $sheet = $book->getSheetByName($sheetName);
        if (!$sheet) throw ValidationException::withMessages(['file' => "Sheet {$sheetName} tidak ditemukan."]);
        if ($sheet->getHighestDataRow() > 501) throw ValidationException::withMessages(['file' => 'Maksimum 500 baris data dalam satu import.']);

        $header = [];
        foreach ($expectedHeaders as $offset => $_) {
            $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($offset + 1).'1');
            $header[] = trim((string) $cell->getValue());
        }
        if ($header !== $expectedHeaders) {
            throw ValidationException::withMessages(['file' => "Judul kolom sheet {$sheetName} tidak sesuai template. Jangan mengubah atau mengurutkan ulang kolom."]);
        }

        $rows = [];
        $errors = [];
        for ($rowNumber = 2; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
            $values = [];
            foreach ($expectedHeaders as $offset => $key) {
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($offset + 1).$rowNumber);
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $errors[] = "Baris {$rowNumber}: formula Excel tidak diizinkan; tempel sebagai nilai.";
                }
                $value = $cell->getValue();
                if (is_string($value)) $value = trim($value);
                if ($value instanceof \DateTimeInterface) $value = $value->format('Y-m-d');
                $values[$key] = $value;
            }
            if (collect($values)->every(fn ($value) => $value === null || $value === '')) continue;
            $values['_row'] = $rowNumber;
            $rows[] = $values;
        }
        $this->failOnErrors($errors);
        return $rows;
    }

    private function importPartners(array $rows, User $user, Request $request): array
    {
        $errors = [];
        $planned = [];
        $codes = $sobatIds = [];
        foreach ($rows as $row) {
            $line = $row['_row'];
            $code = $this->text($row, 'kodeMitra');
            $name = $this->text($row, 'nama');
            $type = strtoupper($this->text($row, 'jenis'));
            $sobatId = $this->nullableText($row, 'sobatId');
            $email = $this->nullableText($row, 'email');
            if ($code === '' || mb_strlen($code) > 50) $errors[] = "Baris {$line}: kodeMitra wajib diisi (maksimum 50 karakter).";
            if ($name === '' || mb_strlen($name) > 180) $errors[] = "Baris {$line}: nama wajib diisi (maksimum 180 karakter).";
            if (!in_array($type, ['PERORANGAN', 'LEMBAGA', 'PERUSAHAAN'], true)) $errors[] = "Baris {$line}: jenis harus PERORANGAN, LEMBAGA, atau PERUSAHAAN.";
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Baris {$line}: format email tidak valid.";
            if ($email && mb_strlen($email) > 255) $errors[] = "Baris {$line}: email melebihi 255 karakter.";
            if ($sobatId && mb_strlen($sobatId) > 60) $errors[] = "Baris {$line}: sobatId melebihi 60 karakter.";
            if ($code !== '' && (isset($codes[$code]) || Partner::where('partner_code', $code)->exists())) $errors[] = "Baris {$line}: kode mitra {$code} sudah digunakan.";
            if ($sobatId && (isset($sobatIds[$sobatId]) || Partner::where('sobat_id', $sobatId)->exists())) $errors[] = "Baris {$line}: sobatId {$sobatId} sudah digunakan.";
            if ($code !== '') $codes[$code] = true;
            if ($sobatId) $sobatIds[$sobatId] = true;
            $planned[] = [
                'partner_code' => $code, 'sobat_id' => $sobatId, 'full_name' => $name,
                'partner_type' => $type, 'phone' => $this->limit($row, 'telepon', 30, $line, $errors),
                'email' => $email, 'address' => $this->limit($row, 'alamat', 500, $line, $errors),
                'district' => $this->limit($row, 'kecamatan', 100, $line, $errors),
                'village' => $this->limit($row, 'desa', 100, $line, $errors),
                'province' => $this->limit($row, 'provinsi', 100, $line, $errors),
            ];
        }
        $this->failOnErrors($errors);

        DB::transaction(function () use ($planned, $user, $request) {
            foreach ($planned as $item) {
                Partner::create($item + [
                    'verification_status' => 'MENUNGGU_VERIFIKASI',
                    'verification_note' => null,
                    'is_active' => true,
                    'is_demo' => false,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
            }
            app(AuditLogger::class)->record($request, 'EXCEL_IMPORT_COMPLETED', 'ExcelImport', 'mitra', null, [
                'type' => 'mitra', 'created' => count($planned), 'verification_status' => 'MENUNGGU_VERIFIKASI',
            ], $user->id);
        });
        return $this->result('mitra', count($planned), 'Mitra diimpor sebagai MENUNGGU_VERIFIKASI. Lakukan pemeriksaan melalui alur verifikasi.');
    }

    private function importActivities(array $rows, User $user, Request $request): array
    {
        $errors = [];
        $groups = [];
        foreach ($rows as $row) {
            $line = $row['_row'];
            $code = $this->text($row, 'kodeKegiatan');
            if ($code === '' || mb_strlen($code) > 50) { $errors[] = "Baris {$line}: kodeKegiatan wajib diisi (maksimum 50 karakter)."; continue; }
            if (!isset($groups[$code])) $groups[$code] = ['base' => $row, 'targets' => [], 'rows' => [$line]];
            else {
                $groups[$code]['rows'][] = $line;
                foreach (['namaKegiatan','tahun','periode','penanggungJawab','jenisKegiatan','uraian','wilayah','tanggalMulai','tanggalSelesai','kebutuhanMitra','persyaratan'] as $field) {
                    if ($this->normalized($groups[$code]['base'][$field]) !== $this->normalized($row[$field])) {
                        $errors[] = "Baris {$line}: data utama kegiatan {$code} berbeda antar target.";
                        break;
                    }
                }
            }
            $targetDescription = $this->text($row, 'uraianTarget');
            $targetVolume = $this->number($row['volumeTarget']);
            if ($targetDescription === '' || mb_strlen($targetDescription) > 500) $errors[] = "Baris {$line}: uraianTarget wajib diisi (maksimum 500 karakter).";
            if ($targetVolume === null || $targetVolume <= 0) $errors[] = "Baris {$line}: volumeTarget harus angka lebih dari nol.";
            $groups[$code]['targets'][] = [
                'description' => $targetDescription,
                'target_volume' => $targetVolume ?? 0,
                'unit' => $this->limit($row, 'satuanTarget', 40, $line, $errors),
                'territory' => $this->limit($row, 'wilayahTarget', 150, $line, $errors),
                'starts_on' => $this->date($row['mulaiTarget'], $line, 'mulaiTarget', $errors, true),
                'ends_on' => $this->date($row['selesaiTarget'], $line, 'selesaiTarget', $errors, true),
            ];
        }

        $planned = [];
        foreach ($groups as $code => $group) {
            $row = $group['base']; $line = $group['rows'][0];
            if (Activity::where('code', $code)->exists()) $errors[] = "Baris {$line}: kode kegiatan {$code} sudah digunakan.";
            $name = $this->text($row, 'namaKegiatan');
            $year = $this->text($row, 'tahun');
            $responsible = $this->text($row, 'penanggungJawab');
            $start = $this->date($row['tanggalMulai'], $line, 'tanggalMulai', $errors);
            $end = $this->date($row['tanggalSelesai'], $line, 'tanggalSelesai', $errors);
            $need = $this->number($row['kebutuhanMitra']);
            if ($name === '' || mb_strlen($name) > 200) $errors[] = "Baris {$line}: namaKegiatan wajib diisi (maksimum 200 karakter).";
            if (!preg_match('/^\d{4}$/', $year)) $errors[] = "Baris {$line}: tahun harus empat digit.";
            if ($responsible === '' || mb_strlen($responsible) > 180) $errors[] = "Baris {$line}: penanggungJawab wajib diisi (maksimum 180 karakter).";
            if (!$start || !$end || $end < $start) $errors[] = "Baris {$line}: tanggal kegiatan tidak valid.";
            if ($need === null || $need < 1 || floor($need) !== $need) $errors[] = "Baris {$line}: kebutuhanMitra harus bilangan bulat minimal 1.";
            if ($group['targets'] === [] || count($group['targets']) > 50) $errors[] = "Baris {$line}: kegiatan harus memiliki 1–50 target.";
            if (count($group['targets']) > 50) continue;
            foreach ($group['targets'] as $target) {
                if ($target['starts_on'] && $target['ends_on'] && $target['ends_on'] < $target['starts_on']) $errors[] = "Baris {$line}: rentang tanggal target tidak valid.";
            }
            $planned[] = [
                'code' => $code, 'name' => $name, 'year' => (int) $year,
                'period' => $this->limit($row, 'periode', 100, $line, $errors),
                'responsible_name' => $responsible,
                'activity_type' => $this->limit($row, 'jenisKegiatan', 100, $line, $errors),
                'description' => $this->limit($row, 'uraian', 5000, $line, $errors),
                'territory' => $this->limit($row, 'wilayah', 200, $line, $errors),
                'starts_on' => $start, 'ends_on' => $end,
                'partner_need' => (int) ($need ?? 0),
                'requirements' => array_values(array_filter(array_map('trim', explode('|', $this->text($row, 'persyaratan'))))),
                'targets' => $group['targets'],
            ];
        }
        $this->failOnErrors($errors);

        DB::transaction(function () use ($planned, $user, $request) {
            foreach ($planned as $data) {
                $activity = Activity::create([
                    'code' => $data['code'], 'name' => $data['name'], 'year' => $data['year'],
                    'period' => $data['period'], 'responsible_user_id' => $user->hasRole('PJK') ? $user->id : null,
                    'responsible_name' => $data['responsible_name'], 'activity_type' => $data['activity_type'],
                    'description' => $data['description'], 'territory' => $data['territory'],
                    'target_volume' => collect($data['targets'])->sum('target_volume'), 'unit' => 'rincian',
                    'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
                    'administrative_requirements' => $data['requirements'], 'partner_need' => $data['partner_need'],
                    'status' => 'DRAFT', 'is_demo' => false, 'created_by' => $user->id, 'updated_by' => $user->id,
                ]);
                foreach ($data['targets'] as $order => $target) $activity->targets()->create($target + ['sort_order' => $order]);
            }
            app(AuditLogger::class)->record($request, 'EXCEL_IMPORT_COMPLETED', 'ExcelImport', 'kegiatan', null, [
                'type' => 'kegiatan', 'created' => count($planned), 'status' => 'DRAFT',
            ], $user->id);
        });
        return $this->result('kegiatan', count($planned), 'Kegiatan diimpor sebagai DRAFT dan target disimpan terpisah. Periksa lalu aktifkan melalui alur kegiatan.');
    }

    private function importAssignments(array $rows, User $user, Request $request): array
    {
        $errors = [];
        $groups = [];
        foreach ($rows as $row) {
            $line = $row['_row'];
            $key = $this->text($row, 'kodePenugasan');
            if ($key === '' || mb_strlen($key) > 80) { $errors[] = "Baris {$line}: kodePenugasan wajib diisi sebagai kunci kelompok target."; continue; }
            if (!isset($groups[$key])) $groups[$key] = ['base' => $row, 'targets' => [], 'rows' => [$line]];
            else {
                $groups[$key]['rows'][] = $line;
                foreach (['kodeKegiatan','kodeMitra','kategoriPetugas','uraianPekerjaan','wilayah','tanggalMulai','tanggalSelesai'] as $field) {
                    if ($this->normalized($groups[$key]['base'][$field]) !== $this->normalized($row[$field])) {
                        $errors[] = "Baris {$line}: data utama penugasan {$key} harus sama pada semua target.";
                        break;
                    }
                }
            }
            $targetCategory = strtoupper($this->text($row, 'kategoriTarget'));
            $volume = $this->number($row['volumeTarget']);
            if ($this->text($row, 'uraianTarget') === '') $errors[] = "Baris {$line}: uraianTarget wajib diisi.";
            if ($volume === null || $volume <= 0) $errors[] = "Baris {$line}: volumeTarget harus angka lebih dari nol.";
            $groups[$key]['targets'][] = [
                'description' => $this->text($row, 'uraianTarget'), 'target_volume' => $volume ?? 0,
                'unit' => $this->limit($row, 'satuanTarget', 40, $line, $errors),
                'territory' => $this->limit($row, 'wilayahTarget', 150, $line, $errors),
                'work_category' => $targetCategory,
                'starts_on' => $this->date($row['mulaiTarget'], $line, 'mulaiTarget', $errors, true),
                'ends_on' => $this->date($row['selesaiTarget'], $line, 'selesaiTarget', $errors, true),
            ];
        }

        $planned = [];
        $scheduledPartners = [];
        foreach ($groups as $key => $group) {
            $row = $group['base']; $line = $group['rows'][0];
            $category = strtoupper($this->text($row, 'kategoriPetugas'));
            if (!in_array($category, ['PENDATAAN_LAPANGAN','PENGOLAHAN','PENDATAAN_DAN_PENGOLAHAN'], true)) $errors[] = "Baris {$line}: kategoriPetugas tidak dikenal.";
            $activity = $this->manageableActivities($user)->where('code', $this->text($row, 'kodeKegiatan'))->where('status', 'AKTIF')->where('is_demo', false)->first();
            $partner = Partner::query()->where('partner_code', $this->text($row, 'kodeMitra'))->first();
            if (!$activity) $errors[] = "Baris {$line}: kegiatan tidak ditemukan, tidak AKTIF, DEMO, atau di luar lingkup akun.";
            if (!$partner || !$partner->is_active || $partner->is_demo || $partner->verification_status !== 'TERVERIFIKASI') $errors[] = "Baris {$line}: mitra harus aktif, terverifikasi, dan non-demo.";
            $start = $this->date($row['tanggalMulai'], $line, 'tanggalMulai', $errors);
            $end = $this->date($row['tanggalSelesai'], $line, 'tanggalSelesai', $errors);
            if (!$start || !$end || $end < $start) $errors[] = "Baris {$line}: tanggal penugasan tidak valid.";
            if ($activity && (($activity->starts_on && $start < $activity->starts_on->format('Y-m-d')) || ($activity->ends_on && $end > $activity->ends_on->format('Y-m-d')))) $errors[] = "Baris {$line}: jadwal penugasan harus berada dalam periode kegiatan.";
            if ($partner && $start && $end) {
                $conflict = AssignmentMember::query()->where('partner_id', $partner->id)->whereHas('assignment', fn (Builder $query) => $query
                    ->whereNotIn('status', ['SELESAI','DITUTUP','DIBATALKAN','SIMULASI_SELESAI'])
                    ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start))->exists();
                if ($conflict) $errors[] = "Baris {$line}: jadwal bertabrakan dengan penugasan aktif mitra {$partner->partner_code}.";
                $plannedRange = $partner->id.'|'.$start.'|'.$end;
                if (isset($scheduledPartners[$partner->id]) && $this->overlap($scheduledPartners[$partner->id], $start, $end)) $errors[] = "Baris {$line}: jadwal bertabrakan dengan penugasan lain di file ini.";
                $scheduledPartners[$partner->id][] = [$start, $end];
            }
            if ($category !== 'PENDATAAN_DAN_PENGOLAHAN') {
                foreach ($group['targets'] as &$target) if ($target['work_category'] === '') $target['work_category'] = $category;
                unset($target);
            }
            $targetCategories = collect($group['targets'])->pluck('work_category')->unique()->values()->all();
            if ($category === 'PENDATAAN_DAN_PENGOLAHAN') {
                if (!in_array('PENDATAAN_LAPANGAN', $targetCategories, true) || !in_array('PENGOLAHAN', $targetCategories, true)) $errors[] = "Baris {$line}: kategori gabungan harus memiliki target Pendataan dan Pengolahan terpisah.";
            } elseif ($targetCategories !== [$category]) {
                $errors[] = "Baris {$line}: kategori target harus sesuai dengan kategoriPetugas.";
            }
            foreach ($group['targets'] as $target) {
                if (!in_array($target['work_category'], ['PENDATAAN_LAPANGAN','PENGOLAHAN'], true)) $errors[] = "Baris {$line}: kategoriTarget wajib Pendataan atau Pengolahan.";
                if ($target['starts_on'] && $target['ends_on'] && $target['ends_on'] < $target['starts_on']) $errors[] = "Baris {$line}: jadwal target tidak valid.";
            }
            $planned[] = [
                'key' => $key, 'activity' => $activity, 'partner' => $partner, 'category' => $category,
                'description' => $this->limit($row, 'uraianPekerjaan', 1000, $line, $errors),
                'territory' => $this->limit($row, 'wilayah', 200, $line, $errors),
                'starts_on' => $start, 'ends_on' => $end, 'targets' => $group['targets'], 'line' => $line,
            ];
        }
        $this->failOnErrors($errors);

        DB::transaction(function () use ($planned, $user, $request) {
            foreach ($planned as $data) {
                $assignment = Assignment::create([
                    'activity_id' => $data['activity']->id,
                    'description' => $data['description'], 'territory' => $data['territory'],
                    'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
                    'status' => 'DITUGASKAN', 'coordinator_id' => $user->hasRole('PJK') ? $user->id : null,
                    'is_demo' => false, 'created_by' => $user->id, 'updated_by' => $user->id,
                ]);
                $member = AssignmentMember::create([
                    'assignment_id' => $assignment->id, 'partner_id' => $data['partner']->id,
                    'status' => 'DITUGASKAN', 'contract_category' => $data['category'],
                    'assigned_at' => now(), 'created_by' => $user->id,
                ]);
                foreach ($data['targets'] as $target) {
                    $member->workItems()->create([
                        'activity_target_id' => null,
                        'description' => $target['description'], 'target_volume' => $target['target_volume'],
                        'unit' => $target['unit'], 'territory' => $target['territory'] ?: $data['territory'],
                        'starts_on' => $target['starts_on'] ?: $data['starts_on'],
                        'ends_on' => $target['ends_on'] ?: $data['ends_on'],
                        'work_category' => $target['work_category'],
                    ]);
                }
            }
            app(AuditLogger::class)->record($request, 'EXCEL_IMPORT_COMPLETED', 'ExcelImport', 'penugasan', null, [
                'type' => 'penugasan', 'created' => count($planned), 'status' => 'DITUGASKAN',
            ], $user->id);
        });
        return $this->result('penugasan', count($planned), 'Penugasan dibuat setelah kegiatan aktif dan mitra terverifikasi lolos pemeriksaan jadwal.');
    }

    private function importReports(array $rows, User $user, Request $request): array
    {
        if (!$user->hasRole('PETUGAS_MITRA') || !$user->partner_id) abort(403, 'Import laporan hanya tersedia untuk akun mitra yang tertaut.');
        $errors = [];
        $groups = [];
        foreach ($rows as $row) {
            $line = $row['_row'];
            $key = $this->text($row, 'kodeLaporan');
            if ($key === '') { $errors[] = "Baris {$line}: kodeLaporan wajib diisi sebagai kunci kelompok."; continue; }
            if (!isset($groups[$key])) $groups[$key] = ['base' => $row, 'items' => [], 'rows' => [$line]];
            else {
                $groups[$key]['rows'][] = $line;
                foreach (['nomorSPK','ringkasan'] as $field) if ($this->normalized($groups[$key]['base'][$field]) !== $this->normalized($row[$field])) $errors[] = "Baris {$line}: nomorSPK dan ringkasan harus sama dalam satu kodeLaporan.";
            }
            $actual = $this->number($row['realisasi']);
            $description = $this->text($row, 'uraianTarget');
            $category = strtoupper($this->text($row, 'kategoriTarget'));
            if ($description === '') $errors[] = "Baris {$line}: uraianTarget wajib diisi sama persis dengan uraian pada SPK.";
            if (!in_array($category, ['PENDATAAN_LAPANGAN','PENGOLAHAN'], true)) $errors[] = "Baris {$line}: kategoriTarget wajib Pendataan Lapangan atau Pengolahan.";
            if ($actual === null || $actual < 0) $errors[] = "Baris {$line}: realisasi harus angka nol atau lebih.";
            $groups[$key]['items'][] = ['description' => $description, 'category' => $category, 'actual' => $actual ?? 0, 'note' => $this->limit($row, 'catatan', 2000, $line, $errors), 'line' => $line];
        }

        $planned = [];
        foreach ($groups as $key => $group) {
            $row = $group['base']; $line = $group['rows'][0];
            $spkNumber = $this->text($row, 'nomorSPK');
            $summary = $this->text($row, 'ringkasan');
            if ($summary === '' || mb_strlen($summary) > 5000) $errors[] = "Baris {$line}: ringkasan wajib diisi (maksimum 5000 karakter).";
            $spk = Document::query()->where('document_type', 'SPK')->where('document_number', $spkNumber)
                ->where('status', 'FINAL')->where('is_demo', false)
                ->with(['assignmentMember.workItems'])->first();
            if (!$spk || (int) $spk->assignmentMember?->partner_id !== (int) $user->partner_id) {
                $errors[] = "Baris {$line}: nomorSPK harus SPK FINAL non-demo milik akun mitra ini.";
                continue;
            }
            $expected = $spk->assignmentMember->workItems;
            if ($spk->document_mode === 'TERPISAH' && $spk->worker_category !== 'PENDATAAN_DAN_PENGOLAHAN') {
                $expected = $expected->where('work_category', $spk->worker_category)->values();
            }
            foreach ($group['items'] as &$item) {
                $matches = $expected->filter(fn ($target) =>
                    trim($target->description) === $item['description'] && $target->work_category === $item['category']
                )->values();
                if ($matches->count() !== 1) {
                    $errors[] = "Baris {$item['line']}: kombinasi uraianTarget dan kategoriTarget tidak cocok tepat dengan satu target SPK.";
                    $item['id'] = null;
                } else {
                    $item['id'] = (int) $matches->first()->id;
                }
            }
            unset($item);
            $expectedIds = $expected->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $providedIds = collect($group['items'])->pluck('id')->filter()->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($expectedIds !== $providedIds) $errors[] = "Baris {$line}: realisasi harus memuat semua target dalam SPK tersebut tepat satu kali.";
            if (WorkReport::where('assignment_member_id', $spk->assignment_member_id)->where('spk_document_id', $spk->id)
                ->whereIn('status', ['DRAFT','DIAJUKAN','PERLU_PERBAIKAN','DITERIMA'])->exists()) {
                $errors[] = "Baris {$line}: laporan aktif untuk SPK {$spkNumber} sudah ada.";
            }
            if (isset($planned[$spk->id])) $errors[] = "Baris {$line}: lebih dari satu kelompok laporan memakai SPK yang sama.";
            $itemMap = $expected->keyBy(fn ($item) => (int) $item->id);
            foreach ($group['items'] as $item) if (!isset($itemMap[$item['id']])) $errors[] = "Baris {$item['line']}: target bukan bagian dari SPK {$spkNumber}.";
            $planned[$spk->id] = ['key' => $key, 'spk' => $spk, 'summary' => $summary, 'items' => $group['items'], 'itemMap' => $itemMap];
        }
        $this->failOnErrors($errors);

        DB::transaction(function () use ($planned, $user, $request) {
            foreach ($planned as $data) {
                $report = WorkReport::create([
                    'assignment_member_id' => $data['spk']->assignment_member_id,
                    'spk_document_id' => $data['spk']->id,
                    'status' => 'DRAFT', 'summary' => $data['summary'],
                    'submitted_by' => $user->id, 'revision' => 1,
                ]);
                foreach ($data['items'] as $item) {
                    $target = $data['itemMap'][$item['id']];
                    $report->items()->create([
                        'assignment_work_item_id' => $target->id,
                        'description' => $target->description,
                        'actual_volume' => $item['actual'],
                        'unit' => $target->unit,
                        'note' => $item['note'],
                        'revision' => 1,
                    ]);
                }
            }
            app(AuditLogger::class)->record($request, 'EXCEL_IMPORT_COMPLETED', 'ExcelImport', 'laporan', null, [
                'type' => 'laporan', 'created' => count($planned), 'status' => 'DRAFT',
            ], $user->id);
        });
        return $this->result('laporan', count($planned), 'Laporan disimpan sebagai DRAFT milik akun mitra. Tambahkan lampiran, lalu ajukan untuk pemeriksaan.');
    }

    private function manageableActivities(User $user): Builder
    {
        $query = Activity::query();
        if ($user->hasRole('ADMIN_BPS')) return $query;
        if ($user->hasRole('PJK')) return $query->where(fn (Builder $builder) => $builder
            ->where('responsible_user_id', $user->id)
            ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('coordinator_id', $user->id)));
        if ($user->hasRole('OPERATOR')) return $query->where('created_by', $user->id);
        abort(403, 'Peran tidak dapat mengelola kegiatan untuk import penugasan.');
    }

    private function text(array $row, string $field): string
    {
        return trim((string) ($row[$field] ?? ''));
    }

    private function nullableText(array $row, string $field): ?string
    {
        $value = $this->text($row, $field);
        return $value === '' ? null : $value;
    }

    private function normalized(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        return trim((string) ($value ?? ''));
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;
        return (float) $value;
    }

    private function integer(mixed $value): ?int
    {
        $number = $this->number($value);
        return $number !== null && floor($number) === $number ? (int) $number : null;
    }

    private function limit(array $row, string $field, int $maximum, int $line, array &$errors): ?string
    {
        $value = $this->nullableText($row, $field);
        if ($value !== null && mb_strlen($value) > $maximum) $errors[] = "Baris {$line}: {$field} melebihi {$maximum} karakter.";
        return $value;
    }

    private function date(mixed $value, int $line, string $field, array &$errors, bool $optional = false): ?string
    {
        if ($value === null || $value === '') {
            if (!$optional) $errors[] = "Baris {$line}: {$field} wajib berformat YYYY-MM-DD.";
            return null;
        }
        try {
            if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
            if (is_numeric($value)) return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim((string) $value));
            if (!$date || $date->format('Y-m-d') !== trim((string) $value)) throw new \RuntimeException();
            return $date->format('Y-m-d');
        } catch (Throwable) {
            $errors[] = "Baris {$line}: {$field} harus berformat YYYY-MM-DD.";
            return null;
        }
    }

    private function overlap(array $existingRanges, ?string $start, ?string $end): bool
    {
        if (!$start || !$end) return false;
        foreach ($existingRanges as [$from, $to]) if ($from <= $end && $to >= $start) return true;
        return false;
    }

    private function failOnErrors(array $errors): void
    {
        if ($errors !== []) throw ValidationException::withMessages(['rows' => array_values(array_unique($errors))]);
    }

    private function result(string $type, int $count, string $note): array
    {
        return ['ok' => true, 'type' => $type, 'created' => $count, 'message' => "{$count} kelompok berhasil diimpor.", 'note' => $note];
    }
}
