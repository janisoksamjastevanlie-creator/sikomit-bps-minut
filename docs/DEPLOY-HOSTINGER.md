# Target Deployment — Hostinger

Dokumen ini menetapkan Hostinger sebagai target deployment akhir. **Ini adalah rencana, bukan bukti bahwa aplikasi sudah di-deploy atau siap produksi.** Paket/fitur Hostinger harus diverifikasi kembali di hPanel saat konfigurasi akhir.

## Bentuk deployment yang dituju

```text
Android release ── HTTPS ──┐
Web administrasi ─ HTTPS ──┴──> Laravel 12 API/Web (Hostinger PHP)
                                      │
                                      ├── MySQL/MariaDB bersama
                                      └── private document storage
```

Android dan web (bila tetap digunakan) menggunakan satu API dan satu sumber data. File dokumen tidak disimpan di direktori publik. APK release hanya berisi URL API HTTPS, tanpa password database atau token rahasia.

## Prasyarat sebelum deployment

1. Paket Hostinger mendukung PHP 8.2+ untuk Laravel 12, Composer 2, `pdo_mysql`, URL rewrite, dan document root yang menunjuk ke `backend/public`; atau gunakan VPS. Verifikasi fitur/versi paket pada hPanel.
2. Catat domain/subdomain API, username SSH, port, direktori deployment, dan MySQL database khusus dari hPanel.
3. Database Laravel MySQL/MariaDB runtime harus lulus migrasi, authorization test, dan uji backup/restore.
4. Produk deployment hanya mengekspos API/halaman administrasi dalam ruang lingkup SPK–BAST. Aplikasi Node/JSON lama tidak boleh dipasang sebagai runtime baru dan arsip lama tidak dihapus tanpa kebijakan migrasi.
5. Tidak ada data operasional, unggahan pribadi, backup, `.env`, private key, atau signing key Android dalam GitHub.
6. Password bootstrap diganti dengan alur pembuatan akun awal yang aman. Tidak boleh ada akun/password default produksi.
7. Pemeriksaan otorisasi server, HTTPS, batas unggahan, penyimpanan privat, audit trail, backup, dan uji pemulihan telah lulus.
8. Templat SPK/BAST, kewenangan, penomoran, dan SOP yang diperlukan telah disahkan/dikonfirmasi oleh instansi.

## Prosedur deployment yang direncanakan

1. Visibilitas repo harus disetujui pemilik data; **private adalah default yang disarankan**. Repo public hanya boleh memuat source yang sudah ditinjau dan tidak boleh berisi legacy Node/JSON, data, dokumen contoh terisi, secrets, atau konfigurasi operasional.
2. Siapkan database MySQL/MariaDB khusus aplikasi dan user database dengan hak minimum; jangan gunakan akun `root`.
3. Deploy Laravel pada subdomain HTTPS; jalankan `composer install --no-dev --optimize-autoloader`, konfigurasi document root ke `backend/public`, dan set environment melalui panel/SSH (bukan GitHub).
4. Jalankan migrasi Laravel terhadap database baru yang telah diverifikasi. Jangan migrasikan JSON legacy tanpa backup, rekonsiliasi, persetujuan, dan kebijakan retensi.
5. Simpan dokumen di disk privat di luar public root; uji unduhan melalui authorization middleware.
6. Verifikasi health check, login, RBAC, isolasi data mitra, API dokumen, audit trail, backup/restore, serta akses dari jaringan luar.
7. Build APK release dengan `-PAPI_BASE_URL=https://<subdomain-api>/`, instal uji, lalu lakukan UAT.

## Status saat ini

- Target Hostinger telah ditetapkan, tetapi paket, domain, SSH user, dan direktori deployment belum dikonfirmasi.
- Laravel 12, Sanctum, migrations MySQL, API autentikasi, mitra/verifikasi, kegiatan, penugasan, dan dashboard administratif sedang dibangun.
- Database lokal Laravel terpisah `sikomit_local` menggunakan MySQL/MariaDB XAMPP. Node/JSON lokal lama tidak digunakan oleh backend Laravel.
- APK Android saat ini masih memakai kontrak/token lama dan belum selesai diarahkan ke Laravel API.
- Runtime Laravel lokal belum seluruhnya memiliki data operasional maupun modul SPK/BAST; belum ada deployment, pemeriksaan keamanan produksi, atau UAT.
- Belum ada deployment, migrasi database runtime, pemeriksaan keamanan produksi, atau UAT.

**Jangan menjalankan backend saat ini langsung di Hostinger sebagai aplikasi produksi.**
