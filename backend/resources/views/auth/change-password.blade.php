<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Perbarui Password · SiKomit</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-body">
<main class="password-card">
    <span class="form-kicker">KEAMANAN AKUN</span>
    <h1>Perbarui password</h1>
    <p class="muted">{{ !empty($forced) ? 'Password awal wajib diganti sebelum melanjutkan.' : 'Gunakan password unik minimal 12 karakter.' }}</p>
    @if ($errors->any()) <div class="notice notice-error">{{ $errors->first() }}</div> @endif
    <form method="post" action="{{ route('password.update') }}" class="form-stack">
        @csrf
        <label for="current_password">Password saat ini</label>
        <div class="password-field">
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            <button class="password-toggle" type="button" data-password-toggle aria-controls="current_password" aria-label="Tampilkan password" aria-pressed="false"></button>
        </div>
        <label for="password">Password baru</label>
        <div class="password-field">
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
            <button class="password-toggle" type="button" data-password-toggle aria-controls="password" aria-label="Tampilkan password" aria-pressed="false"></button>
        </div>
        <label for="password_confirmation">Ulangi password baru</label>
        <div class="password-field">
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
            <button class="password-toggle" type="button" data-password-toggle aria-controls="password_confirmation" aria-label="Tampilkan password" aria-pressed="false"></button>
        </div>
        <button class="primary-button" type="submit">Simpan password</button>
    </form>
</main>
</body>
</html>
