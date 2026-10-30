@extends('layouts.app')

@section('title', 'Demo End-to-End')

@section('content')
@php
    $demoReport = $reports->first();
    $demoSpk = $documents->firstWhere('document_type', 'SPK');
    $demoBast = $documents->firstWhere('document_type', 'BAST');
@endphp
<div class="page-heading">
    <div><span class="eyebrow">SIMULASI LOKAL</span><h1>Demo End-to-End</h1><p>Contoh alur SiKomit dari mitra sampai arsip simulasi.</p></div>
    <a class="secondary-button demo-refresh" href="{{ route('demo.index') }}">Muat ulang demo</a>
</div>

<div class="notice notice-warning demo-warning"><strong>PERHATIAN — DATA DEMO SAJA.</strong> Semua tahap di halaman ini disiapkan seeder, bukan tindakan pemeriksaan atau persetujuan pejabat. Status “simulasi selesai” bukan finalisasi resmi. PDF demo tidak memakai template resmi, tidak memiliki tanda tangan/TTE, dan tidak boleh digunakan sebagai dokumen kerja.</div>

<div class="demo-timeline">
    <article class="demo-stage">
        <div class="demo-stage-number">01</div>
        <div class="demo-stage-content">
            <div class="demo-stage-heading"><div><span class="eyebrow">MITRA</span><h2>Data petugas simulasi</h2></div><span class="demo-state">DEMO</span></div>
            @forelse ($partners as $partner)
                <div class="demo-data-row"><strong>{{ $partner->full_name }}</strong><span>{{ str_replace('_', ' ', $partner->verification_status) }}</span></div>
            @empty
                <p class="muted">Jalankan DemoDataSeeder untuk mengisi skenario.</p>
            @endforelse
        </div>
    </article>

    <article class="demo-stage">
        <div class="demo-stage-number">02</div>
        <div class="demo-stage-content">
            <div class="demo-stage-heading"><div><span class="eyebrow">KEGIATAN</span><h2>Target dan kategori pekerjaan</h2></div><span class="demo-state">DEMO</span></div>
            @foreach ($activities as $activity)
                <div class="demo-activity-block">
                    <strong>{{ $activity->name }}</strong><small>{{ $activity->code }} · {{ $activity->year }}</small>
                    @foreach ($activity->targets as $target)
                        <div class="demo-work-item"><span>{{ $target->description }}</span><span>{{ number_format((float) $target->target_volume, 0, ',', '.') }} {{ $target->unit }}</span></div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </article>

    <article class="demo-stage">
        <div class="demo-stage-number">03</div>
        <div class="demo-stage-content">
            <div class="demo-stage-heading"><div><span class="eyebrow">PENUGASAN</span><h2>Rincian tetap terpisah</h2></div><span class="demo-state">DEMO</span></div>
            @foreach ($assignments as $assignment)
                <div class="demo-activity-block">
                    <strong>{{ $assignment->activity?->name }}</strong><small>{{ $assignment->members->count() }} petugas · {{ $assignment->starts_on?->format('d/m/Y') }} – {{ $assignment->ends_on?->format('d/m/Y') }} · {{ $assignment->status }}</small>
                    @foreach ($assignment->members as $member)
                        <p class="demo-member-name">{{ $member->partner?->full_name }} · {{ str_replace('_', ' ', $member->contract_category) }}</p>
                        @foreach ($member->workItems as $item)
                            <div class="demo-work-item"><span>{{ $item->description }} <small>({{ str_replace('_', ' ', $item->work_category) }})</small></span><span>{{ number_format((float) $item->target_volume, 0, ',', '.') }} {{ $item->unit }}</span></div>
                        @endforeach
                    @endforeach
                </div>
            @endforeach
        </div>
    </article>

    <article class="demo-stage">
        <div class="demo-stage-number">04</div>
        <div class="demo-stage-content">
            <div class="demo-stage-heading"><div><span class="eyebrow">LAPORAN HASIL</span><h2>Penerimaan hasil simulasi</h2></div><span class="demo-state">DEMO</span></div>
            @if ($demoReport)
                <strong>{{ $demoReport->member?->partner?->full_name }} · {{ $demoReport->member?->assignment?->activity?->name }}</strong>
                <p class="demo-copy">{{ $demoReport->summary }}</p>
                @foreach ($demoReport->items as $item)
                    <div class="demo-work-item"><span>{{ $item->description }}</span><span>Realisasi DEMO: {{ number_format((float) $item->actual_volume, 2, ',', '.') }} {{ $item->unit }}</span></div>
                @endforeach
                <div class="demo-stage-footnote">Status data: {{ $demoReport->status }} · Bukan pemeriksaan atau penerimaan hasil nyata.</div>
            @else
                <p class="muted">Belum ada laporan demo.</p>
            @endif
        </div>
    </article>

    <article class="demo-stage demo-stage-final">
        <div class="demo-stage-number">05</div>
        <div class="demo-stage-content">
            <div class="demo-stage-heading"><div><span class="eyebrow">ARSIP SIMULASI</span><h2>SPK dan BAST demo</h2></div><span class="demo-state">BUKAN FINAL RESMI</span></div>
            <p class="demo-copy">Kedua PDF berikut dibuat khusus untuk memperagakan unduhan arsip. Tidak ada tanda tangan yang direkam.</p>
            <div class="demo-document-grid">
                @foreach ([$demoSpk, $demoBast] as $document)
                    @if ($document)
                        <div class="demo-document-card">
                            <span class="demo-document-type">{{ $document->document_type }} · SIMULASI</span>
                            <strong>{{ $document->document_number }}</strong>
                            <small>{{ $document->assignmentMember?->partner?->full_name }}</small>
                            <a class="secondary-button" href="{{ url('/api/v2/documents/'.$document->id.'/file') }}">Unduh PDF DEMO</a>
                        </div>
                    @endif
                @endforeach
            </div>
            @if (!$demoSpk || !$demoBast)
                <p class="muted">Arsip demo belum lengkap. Jalankan DemoDataSeeder kembali.</p>
            @endif
            <div class="demo-stage-footnote">Status khusus: SIMULASI_SELESAI · di luar alur final resmi dan dikecualikan dari ringkasan operasional.</div>
        </div>
    </article>
</div>
@endsection
