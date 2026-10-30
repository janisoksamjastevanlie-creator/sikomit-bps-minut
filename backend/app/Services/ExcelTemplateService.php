<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelTemplateService
{
    private const SHEETS = [
        'Mitra' => ['kodeMitra', 'nama', 'jenis', 'sobatId', 'telepon', 'email', 'alamat', 'kecamatan', 'desa', 'provinsi'],
        'Kegiatan' => ['kodeKegiatan', 'namaKegiatan', 'tahun', 'periode', 'penanggungJawab', 'jenisKegiatan', 'uraian', 'wilayah', 'tanggalMulai', 'tanggalSelesai', 'kebutuhanMitra', 'persyaratan', 'uraianTarget', 'volumeTarget', 'satuanTarget', 'wilayahTarget', 'mulaiTarget', 'selesaiTarget'],
        'Penugasan' => ['kodePenugasan', 'kodeKegiatan', 'kodeMitra', 'kategoriPetugas', 'uraianPekerjaan', 'wilayah', 'tanggalMulai', 'tanggalSelesai', 'uraianTarget', 'volumeTarget', 'satuanTarget', 'kategoriTarget', 'wilayahTarget', 'mulaiTarget', 'selesaiTarget'],
        'Laporan' => ['kodeLaporan', 'nomorSPK', 'ringkasan', 'uraianTarget', 'kategoriTarget', 'realisasi', 'catatan'],
    ];

    public function writeTo(string $output): void
    {
        $book = new Spreadsheet();
        $guide = $book->getActiveSheet();
        $guide->setTitle('Petunjuk');
        $guide->fromArray([
            ['TEMPLATE IMPORT SIKOMIT — TANPA DATA CONTOH'],
            ['Isi hanya sheet sesuai jenis import yang dipilih di aplikasi. Satu upload memproses satu jenis data secara atomik.'],
            ['Mitra: data baru selalu masuk MENUNGGU_VERIFIKASI; proses import tidak memverifikasi mitra.'],
            ['Kegiatan: data baru masuk DRAFT; aktifkan melalui alur kegiatan setelah diperiksa. Satu baris = satu target kegiatan.'],
            ['Penugasan: hanya bisa memakai kegiatan AKTIF dan mitra TERverifikasi non-demo. Satu baris = satu target; kodePenugasan mengelompokkan target.'],
            ['Laporan: hanya akun PETUGAS_MITRA untuk SPK final miliknya. Satu baris = satu realisasi target; uraianTarget harus sama persis dengan SPK dan kategoriTarget wajib diisi. Import membuat DRAFT, bukan mengajukan atau menerima laporan.'],
            ['Tanggal harus berformat YYYY-MM-DD. Desimal gunakan titik. Jangan mengubah judul kolom. Maksimum 500 baris per sheet.'],
            ['Jangan masukkan NIK, NPWP, data rekening, nilai pembayaran, tanda tangan, atau data rahasia.'],
            ['Baris kosong diabaikan. Jika ada kesalahan, seluruh import dibatalkan dan tidak ada baris yang disimpan.'],
        ], null, 'A1');
        $guide->getColumnDimension('A')->setWidth(120);
        $guide->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setARGB('FF07345F');
        $guide->getStyle('A1:A9')->getAlignment()->setWrapText(true)->setVertical('top');
        foreach (range(1, 9) as $row) $guide->getRowDimension($row)->setRowHeight($row === 1 ? 30 : 34);

        foreach (self::SHEETS as $name => $headers) {
            $sheet = $book->createSheet();
            $sheet->setTitle($name);
            $sheet->fromArray([$headers], null, 'A1');
            $last = $sheet->getHighestColumn();
            $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("A1:{$last}1")->getFill()->setFillType('solid')->getStartColor()->setARGB('FF0B5CAB');
            $sheet->getStyle("A1:{$last}1")->getAlignment()->setWrapText(true)->setVertical('center');
            $sheet->getRowDimension(1)->setRowHeight(30);
            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:{$last}1");
            foreach (range(1, count($headers)) as $column) {
                $letter = $sheet->getCell([$column, 1])->getColumn();
                $sheet->getColumnDimension($letter)->setAutoSize(true);
            }
        }

        $this->setListValidation($book->getSheetByName('Mitra'), 'jenis', '"PERORANGAN,LEMBAGA,PERUSAHAAN"');
        $this->setListValidation($book->getSheetByName('Penugasan'), 'kategoriPetugas', '"PENDATAAN_LAPANGAN,PENGOLAHAN,PENDATAAN_DAN_PENGOLAHAN"');
        $this->setListValidation($book->getSheetByName('Penugasan'), 'kategoriTarget', '"PENDATAAN_LAPANGAN,PENGOLAHAN"');
        $this->setListValidation($book->getSheetByName('Laporan'), 'kategoriTarget', '"PENDATAAN_LAPANGAN,PENGOLAHAN"');

        $writer = new Xlsx($book);
        $writer->save($output);
        $book->disconnectWorksheets();
    }

    public function headersFor(string $sheet): array
    {
        return self::SHEETS[$sheet] ?? [];
    }

    private function setListValidation($sheet, string $header, string $formula): void
    {
        $column = array_search($header, self::SHEETS[$sheet->getTitle()], true);
        if ($column === false) return;
        $letter = $sheet->getCell([$column + 1, 1])->getColumn();
        for ($row = 2; $row <= 501; $row++) {
            $validation = $sheet->getCell("{$letter}{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowInputMessage(true)
                ->setShowErrorMessage(true)
                ->setFormula1($formula);
        }
    }
}
