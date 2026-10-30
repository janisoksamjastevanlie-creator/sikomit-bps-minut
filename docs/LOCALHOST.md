# Menjalankan Laravel SiKomit di localhost

Laravel SiKomit berjalan pada `backend/` dengan MariaDB lokal khusus `sikomit_local`. Aplikasi ini terpisah dari server Node, JSON, UI legacy, unggahan, dan data operasional lama.

Jangan gunakan data operasional atau memublikasikan port lokal ke internet.

## Persiapan

1. Jalankan MySQL/MariaDB XAMPP dan pastikan database `sikomit_local` tersedia.
2. Pastikan konfigurasi rahasia hanya ada pada `backend/.env` (file ini tidak boleh dimasukkan ke Git).
3. Dari PowerShell, jalankan migrasi dan server:

```powershell
cd C:\xampp\htdocs\simhon-bps-minut\backend
php artisan migrate --force
php artisan serve --host=0.0.0.0 --port=8000
```

Akun awal dibuat dengan `php artisan sikomit:admin:create <username> <nama>` menggunakan `SIKOMIT_ADMIN_PASSWORD` sementara. Reset akun admin lokal memakai `php artisan sikomit:admin:reset-password <username>` dengan env yang sama; reset hanya diizinkan pada `local/sikomit_local`, mewajibkan password baru saat login, dan mencabut token lama.

Setelah akun Admin BPS dibuat, data tampilan end-to-end opsional dapat dibuat hanya di database lokal:

```powershell
php artisan db:seed --class=DemoDataSeeder --force
```

Seeder menolak environment selain `local` atau database selain `sikomit_local`. Data mencakup tiga mitra/kegiatan/penugasan, satu laporan hasil simulasi diterima, dan satu SPK serta BAST pada arsip **simulasi** dengan PDF privat ber-watermark DEMO. Semua record operasionalnya bertanda `is_demo`/ditautkan ke penugasan DEMO, diberi label di halaman `/demo-alur`, dan dikecualikan dari angka operasional.

Masuk sebagai Admin BPS lokal dan buka `http://127.0.0.1:8000/demo-alur` untuk melihat tahapan serta mengunduh kedua PDF demo.

Menu import Excel ada di `http://127.0.0.1:8000/impor-excel`. Workbook memiliki sheet Mitra, Kegiatan, Penugasan, dan Laporan. Pilih satu jenis per import; validasi seluruh baris harus lulus sebelum perubahan disimpan secara transaksional.

Status arsipnya `SIMULASI_SELESAI`, bukan `FINAL`. PDF tidak memakai template resmi dan tidak memiliki tanda tangan atau TTE. Unduhan demo tetap diberi header `X-SiKomit-Demo: true`; jangan gunakan sebagai dokumen kerja.

API health: `http://127.0.0.1:8000/api/v2/health`. Halaman login web: `http://127.0.0.1:8000/login`. Uji tidak membuat akun operasional; bootstrap admin hanya melalui perintah resmi dengan `SIKOMIT_ADMIN_PASSWORD` kuat.

## Aplikasi Android

- Emulator Android Studio: build debug dengan `-PDEBUG_API_BASE_URL=http://10.0.2.2:8000/`.
- Perangkat fisik melalui USB: buat port reverse (`adb reverse tcp:8000 tcp:8000`), lalu build dengan `-PDEBUG_API_BASE_URL=http://127.0.0.1:8000/`.
- Untuk release, URL HTTPS produksi tetap wajib diberikan lewat `-PAPI_BASE_URL=...`; release production belum dapat dijalankan sampai backend beralih dari JSON ke MySQL/MariaDB dan lolos pemeriksaan keamanan.

Jangan gunakan contoh template terisi sebagai data operasional. Server Node lama dan cara menjalankannya tetap berada di dokumentasi/proyek legacy dan tidak memakai database Laravel ini.
