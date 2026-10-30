<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DemoController;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/password/change', [AuthController::class, 'editPassword'])->name('password.change');
    Route::post('/password/change', [AuthController::class, 'updatePassword'])->name('password.update');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::middleware('role:ADMIN_BPS')->get('/demo-alur', DemoController::class)->name('demo.index');

    Route::middleware('role:ADMIN_BPS,ADMIN_MITRA,OPERATOR,PEMERIKSA,PETUGAS_MITRA')->get('/mitra', fn () => view('admin.partners'))->name('partners.index');
    Route::middleware('role:ADMIN_BPS,PJK,OPERATOR,PETUGAS_MITRA')->get('/kegiatan', fn () => view('admin.activities'))->name('activities.index');
    Route::middleware('role:ADMIN_BPS,PJK,OPERATOR,PETUGAS_MITRA')->get('/penugasan', fn () => view('admin.assignments'))->name('assignments.index');
    Route::middleware('role:ADMIN_BPS,OPERATOR')->get('/template-dokumen', fn () => view('admin.templates', [
        'combinedModeEnabled' => config('sikomit.documents.combined_mode_enabled', false),
    ]))->name('templates.index');
    Route::middleware('role:ADMIN_BPS,ADMIN_MITRA,PJK,OPERATOR,PETUGAS_MITRA')->get('/impor-excel', fn () => view('admin.excel-import'))->name('excel-import.index');
});
