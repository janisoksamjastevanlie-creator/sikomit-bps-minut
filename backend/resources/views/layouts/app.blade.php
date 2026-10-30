<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SiKomit') · BPS Minahasa Utara</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <img class="brand-logo" src="{{ asset('images/sikomit-bps-logo.svg') }}?v=2" alt="SiKomit · Badan Pusat Statistik Kabupaten Minahasa Utara">
        </a>
        <div class="sidebar-section">MENU UTAMA</div>
        <nav class="nav-list" aria-label="Navigasi utama">
            <a class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span>⌂</span>Dashboard</a>
            @if (auth()->user()->hasRole('ADMIN_BPS'))
                <a class="nav-item {{ request()->routeIs('demo.*') ? 'active' : '' }}" href="{{ route('demo.index') }}"><span>▷</span>Demo End-to-End</a>
            @endif
            @if (auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','OPERATOR','PEMERIKSA','PETUGAS_MITRA']))
                <a class="nav-item {{ request()->routeIs('partners.*') ? 'active' : '' }}" href="{{ route('partners.index') }}"><span>♙</span>Mitra</a>
            @endif
            @if (auth()->user()->hasRole(['ADMIN_BPS','PJK','OPERATOR','PETUGAS_MITRA']))
                <a class="nav-item {{ request()->routeIs('activities.*') ? 'active' : '' }}" href="{{ route('activities.index') }}"><span>▦</span>Kegiatan</a>
                <a class="nav-item {{ request()->routeIs('assignments.*') ? 'active' : '' }}" href="{{ route('assignments.index') }}"><span>✓</span>Penugasan</a>
            @endif
            @if (auth()->user()->hasRole(['ADMIN_BPS','OPERATOR']))
                <a class="nav-item {{ request()->routeIs('templates.*') ? 'active' : '' }}" href="{{ route('templates.index') }}"><span>▧</span>Template Dokumen</a>
            @endif
            @if (auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','PJK','OPERATOR','PETUGAS_MITRA']))
                <a class="nav-item {{ request()->routeIs('excel-import.*') ? 'active' : '' }}" href="{{ route('excel-import.index') }}"><span>⇧</span>Import Excel</a>
            @endif
            <span class="nav-item nav-disabled" aria-disabled="true"><span>▤</span>SPK</span>
            <span class="nav-item nav-disabled" aria-disabled="true"><span>▧</span>BAST</span>
            <span class="nav-item nav-disabled" aria-disabled="true"><span>◷</span>Monitoring</span>
            <span class="nav-item nav-disabled" aria-disabled="true"><span>▱</span>Arsip</span>
            <span class="nav-item nav-disabled" aria-disabled="true"><span>▥</span>Laporan</span>
        </nav>
        <div class="sidebar-bottom">
            <div class="scope-chip"><span class="scope-dot"></span>Administrasi SPK · BAST</div>
            <small>BPS Kabupaten Minahasa Utara</small>
        </div>
    </aside>
    <button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Tutup navigasi" tabindex="-1"></button>

    <main class="main-area">
        <header class="topbar">
            <button class="icon-button mobile-menu" id="menuToggle" aria-label="Buka navigasi" aria-expanded="false" aria-controls="sidebar">☰</button>
            <div class="breadcrumbs"><span>SiKomit</span><b>/</b><strong>@yield('title', 'Dashboard')</strong></div>
            <div class="topbar-right">
                <span class="environment-pill"><i></i>LOKAL</span>
                <div class="user-menu"><span class="avatar">{{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}</span><span><strong>{{ auth()->user()->name ?? 'Pengguna' }}</strong><small>{{ auth()->user()?->roles?->first()?->name ?? 'Akun' }}</small></span></div>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit">Keluar</button></form>
            </div>
        </header>
        <section class="page-content">
            @if (session('status')) <div class="notice notice-success">{{ session('status') }}</div> @endif
            @yield('content')
        </section>
    </main>
</div>
<script>
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.getElementById('menuToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const setSidebarOpen = (open) => {
        sidebar?.classList.toggle('sidebar-open', open);
        sidebarBackdrop?.classList.toggle('is-visible', open);
        menuToggle?.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('navigation-open', open);
    };
    menuToggle?.addEventListener('click', () => setSidebarOpen(!sidebar?.classList.contains('sidebar-open')));
    sidebarBackdrop?.addEventListener('click', () => setSidebarOpen(false));
    sidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setSidebarOpen(false)));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setSidebarOpen(false); });
    window.matchMedia('(min-width: 761px)').addEventListener('change', (event) => { if (event.matches) setSidebarOpen(false); });
</script>
</body>
</html>
