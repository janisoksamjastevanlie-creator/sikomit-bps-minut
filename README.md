# SiKomit — BPS Kabupaten Minahasa Utara

Repository berisi backend Laravel 12, aplikasi Android Kotlin/Jetpack Compose, dan dokumentasi alur SiKomit. Sumber Node/JSON lama sengaja tidak disertakan pada repository ini.

## Komponen

- `backend/` — web, REST API v2, migrasi MariaDB, tes, import XLSX, dan workflow dokumen.
- `android/` — aplikasi Android yang menggunakan API Laravel melalui Sanctum.
- `docs/` — kontrak API Laravel, localhost, arsitektur, dan rencana deployment.

## Mulai lokal

Lihat [`docs/LOCALHOST.md`](docs/LOCALHOST.md) untuk konfigurasi database lokal, akun Admin BPS, server, import Excel, dan data demo. Database Laravel lokal harus terpisah dengan nama `sikomit_local`.

```powershell
cd backend
composer install
php artisan migrate --force
php artisan serve --host=0.0.0.0 --port=8000
```

Jangan commit `.env`, kredensial, database, dokumen, atau file unggahan. Data demo berstatus `is_demo` dan tidak boleh dipakai untuk urusan operasional.

## Batas kesiapan

Repository ini bukan pernyataan bahwa aplikasi siap produksi. Template resmi, tanda tangan/TTE, review keamanan, backup/restore, UAT, serta konfigurasi Hostinger masih memerlukan persetujuan dan verifikasi terpisah. PDF DEMO bukan dokumen resmi.
