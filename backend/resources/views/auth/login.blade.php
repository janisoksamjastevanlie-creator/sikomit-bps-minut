<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Masuk · SiKomit</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-body">
<main class="login-shell">
    <section class="login-brand-panel">
        <a class="brand brand-light" href="{{ route('login') }}">
            <img class="brand-logo" src="{{ asset('images/sikomit-bps-logo.svg') }}?v=2" alt="SiKomit · Badan Pusat Statistik Kabupaten Minahasa Utara">
        </a>
        <div class="login-promo">
            <span class="eyebrow">BPS KABUPATEN MINAHASA UTARA</span>
            <h1>Kelola kontrak dan mitra dengan lebih mudah.</h1>
            <p>Alur administrasi kegiatan, penugasan, SPK, dan BAST dalam satu sistem.</p>
            <div class="promo-points"><span>✓ Terintegrasi</span><span>✓ Tertelusur</span><span>✓ Aman</span></div>
        </div>
        <div class="login-footer">Sistem administrasi · Bukan sistem transaksi keuangan</div>
    </section>
    <section class="login-form-panel">
        <div class="login-form-wrap">
            <span class="form-kicker">SELAMAT DATANG</span>
            <h2>Masuk ke SiKomit</h2>
            <p class="muted">Gunakan akun yang dikelola administrator instansi.</p>
            @if ($errors->any())
                <div class="notice notice-error" role="alert">{{ $errors->first() }}</div>
            @endif
            <form method="post" action="{{ route('login.store') }}" class="form-stack">
                @csrf
                <label for="username">Username</label>
                <input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus>
                <label for="password">Password</label>
                <div class="password-field">
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <button class="password-toggle" type="button" data-password-toggle aria-controls="password" aria-label="Tampilkan password" aria-pressed="false"></button>
                </div>
                <label class="remember-row"><input type="checkbox" name="remember" value="1"><span>Ingat sesi pada perangkat ini</span></label>
                <button class="primary-button" type="submit">Masuk <span>→</span></button>
            </form>
            <p class="security-note">Jangan membagikan password atau dokumen mitra kepada pihak yang tidak berwenang.</p>
        </div>
    </section>
</main>
</body>
</html>
