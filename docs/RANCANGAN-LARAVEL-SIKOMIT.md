# Arsitektur Target SiKomit

## Keputusan stack

- **Web/backend:** Laravel 12, Blade + Vite, REST API v2.
- **Database operasional:** MySQL/MariaDB dengan migrations; localhost telah diarahkan ke database terpisah `sikomit_local`.
- **Android:** Kotlin, Jetpack Compose, Retrofit; autentikasi API Sanctum menggunakan bearer token di memori.
- **Dokumen:** template DOCX/PDF privat dengan versi, snapshot, hash, dan arsip final.
- **Target akhir:** Hostinger dengan PHP/Laravel, MySQL/MariaDB, HTTPS, dan storage privat.

Laravel dipilih karena PHP 8.2, Composer 2.9, PDO MySQL, dan MariaDB XAMPP tersedia. Project Laravel baru dibuat di `backend/`; proyek Node lama, JSON, unggahan, dan histori tidak dihapus atau dimigrasikan.

## Batas produk dan data

- Berakhir pada dokumen SPK/BAST final yang diarsipkan.
- Tidak ada modul pembayaran, transfer, pajak pembayaran, rekening untuk pembayaran, akuntansi, atau realisasi keuangan.
- Nilai kontrak hanya boleh terdapat dalam snapshot dokumen bila template disahkan mewajibkannya.
- Tiga kategori: `PENDATAAN_LAPANGAN`, `PENGOLAHAN`, `PENDATAAN_DAN_PENGOLAHAN`.
- Mode dokumen terpisah menjadi default. Mode gabungan tetap nonaktif sampai diperbolehkan pejabat berwenang dan template gabungan disahkan.
- Data contoh dalam template terisi tidak dimasukkan sebagai seed.
- GPS kontinu tidak aktif secara default; perlu dasar hukum/pemrosesan, persetujuan/pemberitahuan, pembatasan retensi, dan review Android sebelum diaktifkan.

## Komponen Laravel

- Sanctum token untuk Android dan session auth untuk web.
- RBAC dengan roles/permissions yang dikonfigurasi; pemeriksaan selalu di backend.
- Migrations saat ini: user/unit/RBAC, mitra/verifikasi, kegiatan/target, penugasan/anggota/rincian target, audit log, work report/revision/evidence, template/document versions, signature evidence, dan arsip.
- API v2: auth, dashboard administrasi, mitra, kegiatan, penugasan, laporan/review pekerjaan, template privat, SPK/BAST draft/review/signature/final/archive.
- Android: login/session Sanctum, mitra, kegiatan, penugasan, dashboard administrasi, serta daftar dan pemeriksaan hasil pekerjaan.
- Web: login, dashboard, mitra, kegiatan, penugasan, dan kelola/review template. Dokumen lengkap belum memiliki UI web end-to-end.
- Import Excel: workbook kosong dengan sheet Mitra, Kegiatan, Penugasan, dan Laporan; validasi atomik per jenis data dengan RBAC serta prasyarat bisnis yang sama seperti API.
- Belum tersedia: provider TTE tersertifikasi, WhatsApp resmi, GPS, antivirus otomatis, backup/restore tervalidasi, security review, UAT, dan deployment.

## Lingkungan

- Pengembangan: Laravel memakai MySQL lokal terpisah `sikomit_local`; seluruh 14 migrasi sudah berjalan. Akun awal tidak memiliki password default. Perintah bootstrap membutuhkan `SIKOMIT_ADMIN_PASSWORD` di environment lokal.
- Data uji end-to-end opsional dibuat dengan `php artisan db:seed --class=DemoDataSeeder --force`; seeder mengunci ke environment `local` dan database `sikomit_local`. Arsip simulasi memakai status `SIMULASI_SELESAI`, tanpa template resmi atau tanda tangan/TTE; semua data DEMO dikecualikan dari ringkasan operasional dan alur penerbitan resmi.
- Android emulator diarahkan ke `http://10.0.2.2:8000/`; perangkat fisik lewat USB memakai `adb reverse tcp:8000 tcp:8000` dan debug URL `http://127.0.0.1:8000/`.
- Hostinger: konfigurasi domain/API, dedicated DB user, environment secrets, HTTPS, private file storage, backup/restore, dan UAT masih perlu dilakukan.

## Legacy

Root `server-kontrak.mjs`, folder `data/`, dan UI Node lama dipertahankan sebagai prototipe/arsip dan tidak menjadi runtime Laravel baru. Tidak ada migrasi data lama sampai ada backup, rekonsiliasi, persetujuan pemilik data, dan rencana khusus yang mengecualikan fitur di luar ruang lingkup.
