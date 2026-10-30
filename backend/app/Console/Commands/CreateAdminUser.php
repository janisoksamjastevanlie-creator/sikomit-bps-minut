<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class CreateAdminUser extends Command
{
    protected $signature = 'sikomit:admin:create {username} {name}';
    protected $description = 'Membuat akun Admin BPS awal tanpa password bawaan.';

    public function handle(): int
    {
        $username = strtolower(trim((string) $this->argument('username')));
        $name = trim((string) $this->argument('name'));
        $password = (string) env('SIKOMIT_ADMIN_PASSWORD', '');

        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
            $this->error('Username harus 3-40 karakter: huruf kecil, angka, titik, garis bawah, atau strip.');
            return self::FAILURE;
        }
        if (User::where('username', $username)->exists()) {
            $this->error('Username sudah digunakan.');
            return self::FAILURE;
        }
        if (mb_strlen($password) < 12 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $this->error('Atur SIKOMIT_ADMIN_PASSWORD di environment lokal (minimal 12 karakter, berisi huruf dan angka).');
            return self::FAILURE;
        }

        $role = Role::where('code', 'ADMIN_BPS')->where('is_active', true)->first();
        if (!$role) {
            $this->error('Peran belum tersedia. Jalankan RolePermissionSeeder terlebih dahulu.');
            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'username' => $username,
            'email' => null,
            'password' => $password,
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $user->roles()->attach($role);

        $this->info("Akun {$username} dibuat dengan peran ADMIN_BPS.");
        $this->warn('Password tidak ditampilkan. Simpan password yang sudah Anda masukkan pada environment secara aman.');
        return self::SUCCESS;
    }
}
