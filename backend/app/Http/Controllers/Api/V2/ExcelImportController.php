<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\ExcelImportService;
use App\Services\ExcelTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelImportController extends Controller
{
    private const IMPORT_ROLES = [
        'mitra' => ['ADMIN_BPS', 'ADMIN_MITRA', 'OPERATOR'],
        'kegiatan' => ['ADMIN_BPS', 'PJK', 'OPERATOR'],
        'penugasan' => ['ADMIN_BPS', 'PJK', 'OPERATOR'],
        'laporan' => ['PETUGAS_MITRA'],
    ];

    public function template(Request $request, ExcelTemplateService $templates): StreamedResponse
    {
        abort_unless($request->user()->hasRole(['ADMIN_BPS', 'ADMIN_MITRA', 'PJK', 'OPERATOR', 'PETUGAS_MITRA']), 403);
        return response()->streamDownload(
            fn () => $templates->writeTo('php://output'),
            'template-import-sikomit.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function import(Request $request, ExcelImportService $importer): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::IMPORT_ROLES))],
            'file' => ['required', 'file', 'extensions:xlsx', 'max:5120'],
        ]);
        abort_unless($request->user()->hasRole(self::IMPORT_ROLES[$data['type']]), 403, 'Peran akun tidak diizinkan untuk jenis import ini.');

        return response()->json($importer->import($data['file'], $data['type'], $request->user(), $request), 201);
    }
}
