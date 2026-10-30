<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetAdminPassword extends Command
{
    protected $signature = 'sikomit:admin:reset-password {username}';
    protected $description = 'Mereset password Admin BPS lokal; password baru wajib diganti saat login.';

    public function handle(): int
    {
        if (!app()->environment('local') || DB::connection()->getDatabaseName() !== 'sikomit_local') {
            $this->error('Reset password hanya diizinkan pada environment local dan database sikomit_local.');
            return self::FAILURE;
        }

        $username = strtolower(trim((string) $this->argument('username')));
        $password = (string) env('SIKOMIT_ADMIN_PASSWORD', '');
        if (mb_strlen($password) < 16 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $this->error('Atur SIKOMIT_ADMIN_PASSWORD sementara (minimal 16 karakter, memuat huruf dan angka).');
            return self::FAILURE;
        }

        $user = User::query()->where('username', $username)->first();
        if (!$user || !$user->hasRole('ADMIN_BPS')) {
            $this->error('Akun Admin BPS tidak ditemukan.');
            return self::FAILURE;
        }
        if (!$user->is_active) {
            $this->error('Akun tidak aktif; reset password tidak mengaktifkan akun.');
            return self::FAILURE;
        }

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
        ])->save();
        $user->tokens()->delete();

        $this->info("Password akun {$username} berhasil direset. Semua token lama dicabut.");
        $this->warn('Password tidak ditampilkan oleh command; salin nilai environment yang Anda gunakan dan ganti saat login pertama.');
        return self::SUCCESS;
    }
}
