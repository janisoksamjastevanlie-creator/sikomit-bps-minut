<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityTarget;
use App\Models\Assignment;
use App\Models\AssignmentMember;
use App\Models\AssignmentWorkItem;
use App\Models\ArchiveEntry;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentVersion;
use App\Models\Partner;
use App\Models\User;
use App\Models\WorkReport;
use App\Models\WorkReportItem;
use App\Models\WorkReviewAction;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local') || DB::connection()->getDatabaseName() !== 'sikomit_local') {
            throw new RuntimeException('Data DEMO hanya boleh dibuat pada environment local dan database sikomit_local.');
        }

        $creator = User::query()->whereHas('roles', fn ($query) => $query->where('code', 'ADMIN_BPS'))
            ->orderBy('id')->first();
        if (!$creator) {
            throw new RuntimeException('Buat akun Admin BPS awal sebelum menjalankan DemoDataSeeder.');
        }

        $start = CarbonImmutable::now()->addMonth()->startOfMonth();
        $end = $start->endOfMonth();
        $year = $start->year;
        $schedule = ['starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()];

        $scenarios = [
            [
                'key' => 'pendataan',
                'partner_code' => 'DEMO-MITRA-PENDATAAN',
                'partner_name' => 'DEMO — Petugas Pendataan Lapangan',
                'category' => 'PENDATAAN_LAPANGAN',
                'activity_code' => "DEMO-PENDATAAN-{$year}",
                'activity_name' => 'DEMO — Pemetaan Wilayah Uji',
                'description' => 'DEMO — Pemetaan blok wilayah untuk simulasi tampilan.',
                'items' => [
                    ['description' => 'DEMO — Pemetaan wilayah uji', 'target_volume' => 12, 'unit' => 'wilayah', 'work_category' => 'PENDATAAN_LAPANGAN'],
                ],
            ],
            [
                'key' => 'pengolahan',
                'partner_code' => 'DEMO-MITRA-PENGOLAHAN',
                'partner_name' => 'DEMO — Petugas Pengolahan',
                'category' => 'PENGOLAHAN',
                'activity_code' => "DEMO-PENGOLAHAN-{$year}",
                'activity_name' => 'DEMO — Pengolahan Data Uji',
                'description' => 'DEMO — Pemeriksaan dan pengolahan data simulasi.',
                'items' => [
                    ['description' => 'DEMO — Pengolahan data uji', 'target_volume' => 40, 'unit' => 'dokumen', 'work_category' => 'PENGOLAHAN'],
                ],
            ],
            [
                'key' => 'gabungan',
                'partner_code' => 'DEMO-MITRA-GABUNGAN',
                'partner_name' => 'DEMO — Petugas Pendataan dan Pengolahan',
                'category' => 'PENDATAAN_DAN_PENGOLAHAN',
                'activity_code' => "DEMO-GABUNGAN-{$year}",
                'activity_name' => 'DEMO — Pendataan dan Pengolahan Uji',
                'description' => 'DEMO — Simulasi dua jenis pekerjaan dengan rincian tetap terpisah.',
                'items' => [
                    ['description' => 'DEMO — Pendataan wilayah uji', 'target_volume' => 8, 'unit' => 'wilayah', 'work_category' => 'PENDATAAN_LAPANGAN'],
                    ['description' => 'DEMO — Pengolahan data uji', 'target_volume' => 25, 'unit' => 'dokumen', 'work_category' => 'PENGOLAHAN'],
                ],
            ],
        ];

        $records = DB::transaction(function () use ($scenarios, $creator, $schedule, $year) {
            $records = [];
            foreach ($scenarios as $scenario) {
                $partner = Partner::firstOrNew(['partner_code' => $scenario['partner_code']]);
                $this->refuseNonDemoOverwrite($partner->exists, (bool) $partner->is_demo, "mitra {$scenario['partner_code']}");
                $partner->fill([
                    'full_name' => $scenario['partner_name'],
                    'partner_type' => 'PERORANGAN',
                    'verification_status' => 'MENUNGGU_VERIFIKASI',
                    'verification_note' => 'DATA SIMULASI SAJA — bukan data mitra operasional.',
                    'is_active' => true,
                    'is_demo' => true,
                    'created_by' => $partner->created_by ?: $creator->id,
                    'updated_by' => $creator->id,
                ])->save();

                $activity = Activity::firstOrNew(['code' => $scenario['activity_code']]);
                $this->refuseNonDemoOverwrite($activity->exists, (bool) $activity->is_demo, "kegiatan {$scenario['activity_code']}");
                $activity->fill([
                    'name' => $scenario['activity_name'],
                    'year' => $year,
                    'period' => $schedule['starts_on'].' — '.$schedule['ends_on'],
                    'responsible_name' => 'Pengelola DEMO',
                    'activity_type' => 'SIMULASI',
                    'description' => $scenario['description'],
                    'territory' => 'Wilayah Uji (DEMO)',
                    'target_volume' => collect($scenario['items'])->sum('target_volume'),
                    'unit' => 'rincian uji',
                    ...$schedule,
                    'administrative_requirements' => ['DEMO — tidak berlaku sebagai persyaratan operasional'],
                    'partner_need' => 1,
                    'status' => 'AKTIF',
                    'is_demo' => true,
                    'created_by' => $activity->created_by ?: $creator->id,
                    'updated_by' => $creator->id,
                ])->save();

                $targetModels = [];
                $workItems = [];
                foreach ($scenario['items'] as $index => $item) {
                    $target = ActivityTarget::firstOrNew([
                        'activity_id' => $activity->id,
                        'description' => $item['description'],
                    ]);
                    $target->fill([
                        'target_volume' => $item['target_volume'],
                        'unit' => $item['unit'],
                        'territory' => 'Wilayah Uji (DEMO)',
                        ...$schedule,
                        'sort_order' => $index + 1,
                    ])->save();
                    $targetModels[] = $target;
                }

                $assignment = Assignment::firstOrNew([
                    'activity_id' => $activity->id,
                    'description' => $scenario['description'],
                ]);
                $this->refuseNonDemoOverwrite($assignment->exists, (bool) $assignment->is_demo, "penugasan {$scenario['key']}");
                $assignment->fill([
                    'territory' => 'Wilayah Uji (DEMO)',
                    ...$schedule,
                    'status' => 'DITUGASKAN',
                    'coordinator_id' => null,
                    'is_demo' => true,
                    'created_by' => $assignment->created_by ?: $creator->id,
                    'updated_by' => $creator->id,
                ])->save();

                $member = AssignmentMember::firstOrNew([
                    'assignment_id' => $assignment->id,
                    'partner_id' => $partner->id,
                ]);
                $member->fill([
                    'status' => 'DITUGASKAN',
                    'contract_category' => $scenario['category'],
                    'assigned_at' => $member->assigned_at ?: now(),
                    'created_by' => $member->created_by ?: $creator->id,
                ])->save();

                foreach ($scenario['items'] as $index => $item) {
                    $workItem = AssignmentWorkItem::firstOrNew([
                        'assignment_member_id' => $member->id,
                        'description' => $item['description'],
                    ]);
                    $workItem->fill([
                        'activity_target_id' => $targetModels[$index]->id,
                        'target_volume' => $item['target_volume'],
                        'unit' => $item['unit'],
                        'territory' => 'Wilayah Uji (DEMO)',
                        ...$schedule,
                        'work_category' => $item['work_category'],
                    ])->save();
                    $workItems[] = $workItem;
                }
                $records[$scenario['key']] = compact('partner', 'activity', 'assignment', 'member', 'targetModels', 'workItems');
            }
            return $records;
        });

        $this->seedCompletedWalkthrough($records['pendataan'], $creator, $year);

        $this->command?->info('Data DEMO siap: 3 mitra, 3 kegiatan, 3 penugasan, 1 laporan simulasi diterima, 1 SPK dan 1 BAST pada arsip simulasi.');
        $this->command?->warn('Semua data dikecualikan dari operasional. PDF hanya simulasi; tidak memakai template resmi atau tanda tangan/TTE.');
    }

    private function seedCompletedWalkthrough(array $scenario, User $actor, int $year): void
    {
        /** @var AssignmentMember $member */
        $member = $scenario['member'];
        /** @var Partner $partner */
        $partner = $scenario['partner'];
        /** @var Activity $activity */
        $activity = $scenario['activity'];
        $scenario['assignment']->forceFill(['status' => 'SIMULASI_SELESAI'])->save();
        $member->forceFill(['status' => 'SIMULASI_SELESAI'])->save();
        $workItems = collect($scenario['workItems']);
        $spkNumber = "DEMO-SPK-{$year}-PENDATAAN-001";
        $bastNumber = "DEMO-BAST-{$year}-PENDATAAN-001";
        $snapshotItems = $workItems->map(fn (AssignmentWorkItem $item) => [
            'assignmentWorkItemId' => (string) $item->id,
            'uraian' => $item->description,
            'target' => (float) $item->target_volume,
            'actualVolume' => round((float) $item->target_volume * 0.9, 2),
            'satuan' => $item->unit ?? '',
            'note' => 'DEMO — realisasi simulasi, bukan hasil pekerjaan nyata.',
            'workCategory' => $item->work_category,
        ])->values()->all();

        $spk = $this->upsertDemoDocument([
            'document_type' => 'SPK',
            'worker_category' => 'PENDATAAN_LAPANGAN',
            'document_number' => $spkNumber,
            'activity_id' => $activity->id,
            'assignment_id' => $scenario['assignment']->id,
            'assignment_member_id' => $member->id,
            'parent_document_id' => null,
            'created_by' => $actor->id,
        ], [
            'document_number' => $spkNumber,
            'document_date' => now()->format('Y-m-d'),
            'partner_name' => $partner->full_name,
            'activity_name' => $activity->name,
            'work_period' => $scenario['assignment']->starts_on->format('d/m/Y').' - '.$scenario['assignment']->ends_on->format('d/m/Y'),
            'work_items' => $snapshotItems,
            'simulation_notice' => 'DEMO — BUKAN DOKUMEN RESMI. Tidak memakai template resmi dan tidak memiliki tanda tangan/TTE.',
        ], $actor);

        $report = WorkReport::firstOrNew([
            'assignment_member_id' => $member->id,
            'spk_document_id' => $spk->id,
        ]);
        $report->fill([
            'status' => 'DITERIMA',
            'summary' => 'DEMO — seluruh rincian direkayasa untuk simulasi alur sampai arsip; bukan laporan pekerjaan nyata.',
            'submitted_by' => $actor->id,
            'submitted_at' => $report->submitted_at ?: now(),
            'accepted_by' => $actor->id,
            'accepted_at' => $report->accepted_at ?: now(),
            'revision' => 1,
        ])->save();

        foreach ($workItems as $item) {
            $actual = round((float) $item->target_volume * 0.9, 2);
            $reportItem = WorkReportItem::firstOrNew([
                'work_report_id' => $report->id,
                'assignment_work_item_id' => $item->id,
            ]);
            $reportItem->fill([
                'description' => $item->description,
                'actual_volume' => $actual,
                'unit' => $item->unit,
                'note' => 'DEMO — realisasi simulasi.',
                'revision' => 1,
            ])->save();
        }
        $reviewAction = WorkReviewAction::firstOrNew([
            'work_report_id' => $report->id,
            'reviewer_id' => $actor->id,
            'revision' => 1,
            'outcome' => 'DEMO_SIMULASI_DITERIMA',
        ]);
        $reviewAction->fill([
            'note' => 'SIMULASI SAJA — tindakan ini bukan pemeriksaan atau penerimaan pekerjaan yang sah.',
            'reviewed_at' => $report->accepted_at,
        ])->save();

        $bastSnapshot = [
            'document_number' => $bastNumber,
            'document_date' => now()->format('Y-m-d'),
            'handover_date' => now()->format('Y-m-d'),
            'partner_name' => $partner->full_name,
            'activity_name' => $activity->name,
            'spk_number' => $spkNumber,
            'verified_results' => collect($snapshotItems)->map(fn ($item) => $item['uraian'].': '.$item['actualVolume'].' '.$item['satuan'])->implode(' | '),
            'work_items' => $snapshotItems,
            'simulation_notice' => 'DEMO — BUKAN BAST RESMI. Tidak memakai template resmi dan tidak memiliki tanda tangan/TTE.',
        ];
        $this->upsertDemoDocument([
            'document_type' => 'BAST',
            'worker_category' => 'PENDATAAN_LAPANGAN',
            'document_number' => $bastNumber,
            'activity_id' => $activity->id,
            'assignment_id' => $scenario['assignment']->id,
            'assignment_member_id' => $member->id,
            'parent_document_id' => $spk->id,
            'created_by' => $actor->id,
        ], $bastSnapshot, $actor);
    }

    private function upsertDemoDocument(array $attributes, array $snapshot, User $actor): Document
    {
        $document = Document::firstOrNew([
            'document_type' => $attributes['document_type'],
            'document_number' => $attributes['document_number'],
        ]);
        $this->refuseNonDemoOverwrite($document->exists, (bool) $document->is_demo, "dokumen {$attributes['document_number']}");
        $document->fill([
            ...$attributes,
            'document_mode' => 'TERPISAH',
            'template_version_id' => null,
            'status' => 'SIMULASI_SELESAI',
            'current_version' => 1,
            'content_snapshot' => $snapshot,
            'is_demo' => true,
            'updated_by' => $actor->id,
            'finalized_at' => null,
            'locked_at' => null,
        ])->save();

        $pdfPath = "sikomit/demo-archives/{$attributes['document_number']}.pdf";
        $pdfBytes = $this->simulationPdf($attributes['document_type'], $snapshot);
        Storage::disk('local')->put($pdfPath, $pdfBytes);
        $sha256 = hash('sha256', $pdfBytes);

        $version = DocumentVersion::firstOrNew(['document_id' => $document->id, 'version' => 1]);
        $version->fill([
            'template_version_id' => null,
            'content_snapshot' => $snapshot,
            'docx_path' => null,
            'pdf_path' => $pdfPath,
            'sha256' => $sha256,
            'created_by' => $actor->id,
        ])->save();

        $archive = ArchiveEntry::firstOrNew([
            'document_id' => $document->id,
            'document_version_id' => $version->id,
        ]);
        $archive->fill([
            'archived_by' => $actor->id,
            'archived_at' => $archive->archived_at ?: now(),
            'sha256' => $sha256,
        ])->save();

        $action = DocumentAction::firstOrNew([
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'action' => 'DEMO_SIMULATION_ARCHIVED',
        ]);
        $action->fill([
            'actor_id' => $actor->id,
            'outcome' => 'SIMULASI_SELESAI',
            'note' => 'Simulasi sampai arsip. Tidak melalui template resmi, pemeriksaan resmi, atau tanda tangan/TTE.',
            'validation_metadata' => ['demo' => true, 'signature_recorded' => false, 'official_template_used' => false],
            'occurred_at' => $archive->archived_at,
        ])->save();

        return $document->fresh();
    }

    private function simulationPdf(string $type, array $snapshot): string
    {
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = collect($snapshot['work_items'] ?? [])->map(fn ($item) => '<tr><td>'.$escape($item['uraian'] ?? '').'</td><td>'.$escape($item['target'] ?? '').' '.$escape($item['satuan'] ?? '').'</td><td>'.$escape($item['actualVolume'] ?? '').' '.$escape($item['satuan'] ?? '').'</td></tr>')->implode('');
        $notice = $escape($snapshot['simulation_notice'] ?? 'DEMO — BUKAN DOKUMEN RESMI.');
        $html = '<!doctype html><html lang="id"><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;color:#18344d;font-size:12px;margin:42px}
            .banner{padding:14px;border:3px solid #b42332;color:#b42332;text-align:center;font-weight:bold;font-size:18px}
            h1{text-align:center;color:#07345f;margin:28px 0 5px} .number{text-align:center;color:#52687b}
            .notice{margin:22px 0;padding:13px;background:#fff4e5;border:1px solid #e7b866;color:#805500;font-weight:bold}
            table{width:100%;border-collapse:collapse;margin-top:18px}th,td{border:1px solid #9aabb9;padding:8px;text-align:left}th{background:#edf4f9}
            .footer{margin-top:36px;padding-top:12px;border-top:1px solid #aab8c5;color:#52687b;font-size:10px}
        </style><body><div class="banner">DEMO · SIMULASI · BUKAN DOKUMEN RESMI</div>
        <h1>'.($type === 'SPK' ? 'SIMULASI SPK' : 'SIMULASI BAST').'</h1>
        <div class="number">'. $escape($snapshot['document_number'] ?? '').'</div>
        <div class="notice">'.$notice.'<br>Template resmi tidak digunakan. Tidak ada tanda tangan manual atau TTE.</div>
        <p><b>Mitra:</b> '.$escape($snapshot['partner_name'] ?? '').'</p>
        <p><b>Kegiatan:</b> '.$escape($snapshot['activity_name'] ?? '').'</p>
        '.($type === 'BAST' ? '<p><b>Referensi SPK DEMO:</b> '.$escape($snapshot['spk_number'] ?? '').'</p>' : '').'
        <table><thead><tr><th>Rincian simulasi</th><th>Target</th><th>Realisasi DEMO</th></tr></thead><tbody>'.$rows.'</tbody></table>
        <div class="footer">File ini hanya untuk demonstrasi aplikasi SiKomit. Bukan kontrak, berita acara, bukti pekerjaan, atau dokumen yang dapat digunakan secara resmi.</div>
        </body></html>';
        $renderer = new Dompdf();
        $renderer->loadHtml($html, 'UTF-8');
        $renderer->setPaper('A4', 'portrait');
        $renderer->render();
        return $renderer->output();
    }

    private function refuseNonDemoOverwrite(bool $exists, bool $isDemo, string $record): void
    {
        if ($exists && !$isDemo) {
            throw new RuntimeException("Seeder menolak mengubah {$record} karena record tersebut bukan data DEMO.");
        }
    }
}
