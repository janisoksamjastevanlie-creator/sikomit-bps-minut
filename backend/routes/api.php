<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V2\AuthController;
use App\Http\Controllers\Api\V2\ActivityController;
use App\Http\Controllers\Api\V2\AssignmentController;
use App\Http\Controllers\Api\V2\PartnerController;
use App\Http\Controllers\Api\V2\DashboardController;
use App\Http\Controllers\Api\V2\AuditLogController;
use App\Http\Controllers\Api\V2\DocumentTemplateController;
use App\Http\Controllers\Api\V2\DocumentController;
use App\Http\Controllers\Api\V2\WorkReportController;
use App\Http\Controllers\Api\V2\ExcelImportController;

Route::prefix('v2')->group(function () {
    Route::get('/health', fn () => response()->json(['ok' => true, 'service' => 'sikomit-api', 'version' => 2]));
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

        Route::middleware('password.changed')->group(function () {
            Route::get('/excel-import/template', [ExcelImportController::class, 'template']);
            Route::post('/excel-import/import', [ExcelImportController::class, 'import']);
        });

        Route::middleware('password.changed')->get('/dashboard/administrasi', [DashboardController::class, 'show']);

        Route::middleware(['password.changed', 'role:ADMIN_BPS,AUDITOR'])->get('/audit', [AuditLogController::class, 'index']);

        Route::middleware(['password.changed', 'role:ADMIN_BPS,OPERATOR'])->group(function () {
            Route::get('/templates', [DocumentTemplateController::class, 'index']);
            Route::get('/templates/{version}/file', [DocumentTemplateController::class, 'file'])->whereNumber('version');
            Route::post('/templates', [DocumentTemplateController::class, 'store']);
        });
        Route::middleware(['password.changed', 'role:ADMIN_BPS'])->post('/templates/{version}/approve', [DocumentTemplateController::class, 'approve'])->whereNumber('version');

        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK,OPERATOR,PEMERIKSA,PETUGAS_MITRA,PEJABAT_PERSETUJU,PENANDATANGAN,AUDITOR'])->group(function () {
            Route::get('/documents', [DocumentController::class, 'index']);
            Route::get('/documents/{document}', [DocumentController::class, 'show'])->whereNumber('document');
            Route::get('/documents/{document}/preview', [DocumentController::class, 'preview'])->whereNumber('document');
        });
        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK,OPERATOR'])->group(function () {
            Route::post('/spk/drafts', [DocumentController::class, 'createSpkDraft']);
            Route::post('/bast/drafts', [DocumentController::class, 'createBastDraft']);
            Route::put('/documents/{document}/draft', [DocumentController::class, 'updateDraft'])->whereNumber('document');
            Route::post('/documents/{document}/submit', [DocumentController::class, 'submit'])->whereNumber('document');
        });
        Route::middleware(['password.changed', 'role:PJK,PEMERIKSA'])->post('/documents/{document}/review', [DocumentController::class, 'review'])->whereNumber('document');
        Route::middleware(['password.changed', 'role:ADMIN_BPS,PEJABAT_PERSETUJU'])->post('/documents/{document}/approve', [DocumentController::class, 'approve'])->whereNumber('document');
        Route::middleware(['password.changed', 'role:PENANDATANGAN,PEJABAT_PERSETUJU,PETUGAS_MITRA'])->post('/documents/{document}/signatures', [DocumentController::class, 'sign'])->whereNumber('document');
        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK,PEMERIKSA'])->post('/documents/{document}/signatures/{signature}/validate', [DocumentController::class, 'validateSignature'])->whereNumber('document')->whereNumber('signature');
        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK,PEMERIKSA,PEJABAT_PERSETUJU,PETUGAS_MITRA'])->get('/documents/{document}/signatures/{signature}/file', [DocumentController::class, 'signatureFile'])->whereNumber('document')->whereNumber('signature');
        Route::middleware(['password.changed', 'role:ADMIN_BPS,PEJABAT_PERSETUJU'])->post('/documents/{document}/finalize', [DocumentController::class, 'finalize'])->whereNumber('document');
        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK,OPERATOR,PEMERIKSA,PETUGAS_MITRA,PEJABAT_PERSETUJU,PENANDATANGAN,AUDITOR'])->get('/documents/{document}/file', [DocumentController::class, 'file'])->whereNumber('document');

        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK,OPERATOR,PEMERIKSA,PETUGAS_MITRA'])->group(function () {
            Route::get('/work-reports', [WorkReportController::class, 'index']);
            Route::get('/work-reports/{report}', [WorkReportController::class, 'show'])->whereNumber('report');
            Route::get('/work-reports/{report}/evidence/{evidence}', [WorkReportController::class, 'downloadEvidence'])->whereNumber('report')->whereNumber('evidence');
        });
        Route::middleware(['password.changed', 'role:PETUGAS_MITRA'])->group(function () {
            Route::post('/work-reports', [WorkReportController::class, 'store']);
            Route::put('/work-reports/{report}', [WorkReportController::class, 'update'])->whereNumber('report');
            Route::post('/work-reports/{report}/submit', [WorkReportController::class, 'submit'])->whereNumber('report');
            Route::post('/work-reports/{report}/evidence', [WorkReportController::class, 'uploadEvidence'])->whereNumber('report');
        });
        Route::middleware(['password.changed', 'role:PJK,PEMERIKSA,ADMIN_BPS'])->group(function () {
            Route::post('/work-reports/{report}/review', [WorkReportController::class, 'review'])->whereNumber('report');
            Route::post('/work-reports/{report}/evidence/{evidence}/review', [WorkReportController::class, 'validateEvidence'])->whereNumber('report')->whereNumber('evidence');
        });

        Route::middleware(['password.changed', 'role:ADMIN_BPS,ADMIN_MITRA,PJK,OPERATOR,PEMERIKSA,PETUGAS_MITRA'])->group(function () {
            Route::get('/mitra', [PartnerController::class, 'index']);
            Route::get('/mitra/{partner}', [PartnerController::class, 'show'])->whereNumber('partner');
        });
        Route::middleware(['password.changed', 'role:ADMIN_BPS,ADMIN_MITRA,OPERATOR'])->group(function () {
            Route::post('/mitra', [PartnerController::class, 'store']);
            Route::put('/mitra/{partner}', [PartnerController::class, 'update'])->whereNumber('partner');
        });
        Route::middleware(['password.changed', 'role:ADMIN_BPS,ADMIN_MITRA,PEMERIKSA'])->post('/mitra/{partner}/verification', [PartnerController::class, 'verify'])->whereNumber('partner');

        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK,OPERATOR,PETUGAS_MITRA'])->group(function () {
            Route::get('/activities', [ActivityController::class, 'index']);
            Route::get('/activities/{activity}', [ActivityController::class, 'show'])->whereNumber('activity');
        });
        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK,OPERATOR'])->group(function () {
            Route::post('/activities', [ActivityController::class, 'store']);
            Route::put('/activities/{activity}', [ActivityController::class, 'update'])->whereNumber('activity');
        });
        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK'])->post('/activities/{activity}/activate', [ActivityController::class, 'activate'])->whereNumber('activity');

        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK,OPERATOR'])->group(function () {
            Route::get('/assignments/options', [AssignmentController::class, 'options']);
            Route::post('/assignments', [AssignmentController::class, 'store']);
        });
        Route::middleware(['password.changed', 'role:ADMIN_BPS,PJK,OPERATOR,PETUGAS_MITRA'])->group(function () {
            Route::get('/assignments', [AssignmentController::class, 'index']);
            Route::get('/assignments/{assignment}', [AssignmentController::class, 'show'])->whereNumber('assignment');
        });
    });
});
