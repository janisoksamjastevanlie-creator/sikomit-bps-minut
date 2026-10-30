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
        <div class="sidebar-section">RUANG KERJA</div>
        <nav class="nav-list" aria-label="Navigasi utama">
            <a class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/></svg></span>Dashboard</a>
            @if (auth()->user()->hasRole('ADMIN_BPS'))
                <a class="nav-item {{ request()->routeIs('demo.*') ? 'active' : '' }}" href="{{ route('demo.index') }}"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z"/></svg></span>Demo End-to-End</a>
            @endif
            @if (auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','OPERATOR','PEMERIKSA','PETUGAS_MITRA','PJK']))
                <div class="sidebar-section nav-section-label">DATA & KEGIATAN</div>
                @if (auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','OPERATOR','PEMERIKSA','PETUGAS_MITRA']))
                    <a class="nav-item {{ request()->routeIs('partners.*') ? 'active' : '' }}" href="{{ route('partners.index') }}"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.8 20v-1.5a6.2 6.2 0 0 1 12.4 0V20M16 5.2a3.5 3.5 0 0 1 0 6.8M17 14a5.2 5.2 0 0 1 4.2 5.1V20"/></svg></span>Mitra</a>
                @endif
                @if (auth()->user()->hasRole(['ADMIN_BPS','PJK','OPERATOR','PETUGAS_MITRA']))
                    <a class="nav-item {{ request()->routeIs('activities.*') ? 'active' : '' }}" href="{{ route('activities.index') }}"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5V12m5 7.5V5m5 14.5v-9m5 9V8"/><path d="M2.5 21h19"/></svg></span>Kegiatan</a>
                    <a class="nav-item {{ request()->routeIs('assignments.*') ? 'active' : '' }}" href="{{ route('assignments.index') }}"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 5 5L20 6"/><path d="M20 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10"/></svg></span>Penugasan</a>
                @endif
            @endif
            @if (auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','OPERATOR','PJK','PETUGAS_MITRA']))
                <div class="sidebar-section nav-section-label">PERANGKAT</div>
                @if (auth()->user()->hasRole(['ADMIN_BPS','OPERATOR']))
                    <a class="nav-item {{ request()->routeIs('templates.*') ? 'active' : '' }}" href="{{ route('templates.index') }}"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3.5h8l4 4V20a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 20V5a1.5 1.5 0 0 1 2-1.5Z"/><path d="M14 4v4h4M8 13h8M8 17h8"/></svg></span>Template Dokumen</a>
                @endif
                @if (auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','PJK','OPERATOR','PETUGAS_MITRA']))
                    <a class="nav-item {{ request()->routeIs('excel-import.*') ? 'active' : '' }}" href="{{ route('excel-import.index') }}"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V3m0 0L7.5 7.5M12 3l4.5 4.5"/><path d="M4 14.5v4A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5v-4"/></svg></span>Import Excel</a>
                @endif
            @endif
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-account-mark"><span class="scope-dot"></span><span>Lingkungan {{ strtoupper(app()->environment()) }}</span></div>
            <small>Badan Pusat Statistik<br>Kabupaten Minahasa Utara</small>
        </div>
    </aside>
    <button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Tutup navigasi" tabindex="-1"></button>

    <main class="main-area">
        <header class="topbar">
            <button class="icon-button mobile-menu" id="menuToggle" aria-label="Buka navigasi" aria-expanded="false" aria-controls="sidebar">☰</button>
            <div class="breadcrumbs"><span>SiKomit</span><b>/</b><strong>@yield('title', 'Dashboard')</strong></div>
            <div class="topbar-right">
                <span class="environment-pill {{ app()->environment('production') ? 'is-production' : 'is-local' }}"><i></i>{{ strtoupper(app()->environment()) }}</span>
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
