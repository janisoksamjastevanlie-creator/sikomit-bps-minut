@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">RINGKASAN ADMINISTRASI</span><h1>Dashboard</h1><p>Pantau kegiatan, mitra, dan penugasan dalam lingkup akses Anda.</p></div>
        <div class="date-chip">{{ now()->translatedFormat('l, d F Y') }}</div>
    </div>

    @if (($demoCounts['partners'] + $demoCounts['activities'] + $demoCounts['assignments'] + $demoCounts['spk'] + $demoCounts['bast']) > 0)
        <div class="notice notice-info"><strong>Mode data uji:</strong> tersedia {{ $demoCounts['partners'] }} mitra, {{ $demoCounts['activities'] }} kegiatan, {{ $demoCounts['assignments'] }} penugasan, {{ $demoCounts['spk'] }} SPK simulasi, dan {{ $demoCounts['bast'] }} BAST simulasi. {{ $demoCounts['archives'] }} file ditandai DEMO di arsip. Semua dikecualikan dari angka operasional; dokumen simulasi bukan dokumen resmi dan tidak memakai tanda tangan/TTE. <a class="demo-notice-link" href="{{ route('demo.index') }}">Lihat alur demo lengkap →</a></div>
    @endif

    <div class="welcome-banner">
        <div class="banner-content">
            <span class="banner-kicker">SISTEM INFORMASI KONTRAK DAN MITRA · BPS MINAHASA UTARA</span>
            <h2>Selamat datang, {{ $user->name }}</h2>
            <p>Ringkasan kegiatan dan mitra sesuai lingkup akses akun Anda.</p>
            <div class="dashboard-actions">
                @if (auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','OPERATOR','PEMERIKSA','PETUGAS_MITRA']))<a href="{{ route('partners.index') }}">Data Mitra <span>→</span></a>@endif
                @if (auth()->user()->hasRole(['ADMIN_BPS','PJK','OPERATOR','PETUGAS_MITRA']))<a href="{{ route('activities.index') }}">Kegiatan <span>→</span></a><a href="{{ route('assignments.index') }}">Penugasan <span>→</span></a>@endif
            </div>
        </div>
        <div class="banner-emblem" aria-hidden="true"><img src="{{ asset('images/bps-logo.svg') }}" alt=""></div>
    </div>

    <div class="metric-grid">
        <article class="metric-card"><div class="metric-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.8 20v-1.5a6.2 6.2 0 0 1 12.4 0V20M16 5.2a3.5 3.5 0 0 1 0 6.8M17 14a5.2 5.2 0 0 1 4.2 5.1V20"/></svg></div><span class="metric-label">Total Mitra</span><strong>{{ number_format($metrics['partners'], 0, ',', '.') }}</strong><small>Dalam lingkup akses</small></article>
        <article class="metric-card"><div class="metric-icon orange"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div><span class="metric-label">Menunggu Verifikasi</span><strong>{{ number_format($metrics['partnersPending'], 0, ',', '.') }}</strong><small>Perlu ditindaklanjuti</small></article>
        <article class="metric-card"><div class="metric-icon green"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5V12m5 7.5V5m5 14.5v-9m5 9V8"/><path d="M2.5 21h19"/></svg></div><span class="metric-label">Kegiatan Aktif</span><strong>{{ number_format($metrics['activitiesActive'], 0, ',', '.') }}</strong><small>dari {{ number_format($metrics['activities'], 0, ',', '.') }} kegiatan</small></article>
        <article class="metric-card"><div class="metric-icon violet"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 5 5L20 6"/><path d="M20 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10"/></svg></div><span class="metric-label">Penugasan Aktif</span><strong>{{ number_format($metrics['assignmentsActive'], 0, ',', '.') }}</strong><small>dari {{ number_format($metrics['assignments'], 0, ',', '.') }} penugasan</small></article>
    </div>

    <div class="dashboard-grid">
        <section class="panel panel-wide">
            <div class="panel-heading"><div><h2>Kegiatan Terbaru</h2><p>Daftar kegiatan sesuai hak akses</p></div><span class="panel-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19.5V12m5 7.5V5m5 14.5v-9m5 9V8"/><path d="M2.5 21h19"/></svg></span></div>
            @forelse ($recentActivities as $activity)
                <div class="activity-row"><span class="activity-dot {{ $activity->status === 'AKTIF' ? 'is-active' : '' }}"></span><div class="row-main"><strong>{{ $activity->name }}</strong><small>{{ $activity->code }} · {{ $activity->year }}{{ $activity->territory ? ' · '.$activity->territory : '' }}</small></div><span class="status-pill status-{{ strtolower($activity->status) }}">{{ str_replace('_', ' ', $activity->status) }}</span></div>
            @empty
                <div class="empty-state"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5V12m5 7.5V5m5 14.5v-9m5 9V8"/><path d="M2.5 21h19"/></svg></span><strong>Belum ada kegiatan</strong><p>Data akan tampil setelah kegiatan dibuat pada sistem.</p></div>
            @endforelse
        </section>
        <section class="panel">
            <div class="panel-heading"><div><h2>Penugasan Terbaru</h2><p>Pekerjaan mitra yang tercatat</p></div><span class="panel-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 12 5 5L20 6"/><path d="M20 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10"/></svg></span></div>
            @forelse ($recentAssignments as $assignment)
                <div class="assignment-row"><strong>{{ $assignment->activity?->name ?? 'Kegiatan' }}</strong><small>{{ $assignment->description }}</small><div class="assignment-meta"><span>{{ $assignment->members->count() }} petugas</span><span class="status-pill status-{{ strtolower($assignment->status) }}">{{ str_replace('_', ' ', $assignment->status) }}</span></div></div>
            @empty
                <div class="empty-state compact"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 5 5L20 6"/><path d="M20 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10"/></svg></span><strong>Belum ada penugasan</strong><p>Penugasan aktif akan muncul di sini.</p></div>
            @endforelse
        </section>
    </div>
    <div class="scope-note"><span>ⓘ</span><p>Dashboard ini hanya menampilkan informasi administrasi. Proses pembayaran tidak tersedia di SiKomit.</p></div>
@endsection
