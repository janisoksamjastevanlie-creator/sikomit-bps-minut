# Acuan UI SiKomit

Desain mengikuti referensi visual BPS yang diberikan pengguna; aset logo publik berada di `backend/public/images/`.

## Arah desain

- Modern Government UI dengan biru utama `#0B5CAB`, navy sidebar, permukaan putih, dan latar abu-biru muda.
- Sidebar responsif, breadcrumb/header, profil pengguna, kartu statistik, status berwarna, tabel/filter, timeline dokumen, serta layout desktop/mobile.
- Seluruh label dan pesan produk berbahasa Indonesia.
- Data KPI harus dari backend; tidak memakai angka statis sebagai data operasional.

## Tiga jenis petugas

1. Petugas Pendataan Lapangan (Pemetaan).
2. Petugas Pengolahan.
3. Petugas Pendataan dan Pengolahan.

Jenis gabungan wajib tetap memisahkan target, jadwal, realisasi, dan hasil pendataan/pengolahan agar dapat diperiksa per jenis pekerjaan.

## Mode dokumen

- **Mode terpisah adalah default**: SPK/BAST Pendataan dan SPK/BAST Pengolahan berdiri sendiri.
- Mode gabungan hanya dapat dipilih bila admin berwenang telah mengaktifkannya setelah kebijakan instansi dikonfirmasi.
- Perubahan mode setelah dokumen ditandatangani memerlukan revisi/pembatalan yang sah dan jejak audit.
- Nominal (bila diwajibkan template) tetap sebagai snapshot dalam dokumen, bukan transaksi atau metrik dashboard.

## Lokasi dan pelacakan

Pelacakan berkelanjutan bukan default. Bila benar-benar diperlukan kegiatan, aktifkan hanya setelah dasar pemrosesan, pemberitahuan/persetujuan, kebijakan retensi, akses, dan persyaratan Android foreground-service disahkan. Check-in/check-out dapat menjadi alternatif yang lebih minim data. Data GPS tidak boleh menjadi satu-satunya bukti pekerjaan.

## Implementasi saat ini

Laravel Blade memakai shell navy/blue, dashboard administrasi, navigasi responsif, halaman mitra/kegiatan/penugasan/template, demo walkthrough, dan import Excel. Android Compose menyediakan dashboard, mitra, kegiatan, penugasan, serta daftar dan pemeriksaan laporan kerja. UI dokumen SPK/BAST end-to-end masih memerlukan implementasi terpisah.
