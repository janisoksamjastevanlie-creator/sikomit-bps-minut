<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionNames = [
            'users.manage' => 'Mengelola pengguna dan peran',
            'partners.read' => 'Melihat data mitra sesuai lingkup',
            'partners.manage' => 'Mengelola data mitra',
            'partners.verify' => 'Memeriksa administrasi mitra',
            'activities.read' => 'Melihat kegiatan sesuai lingkup',
            'activities.manage' => 'Mengelola kegiatan',
            'assignments.read' => 'Melihat penugasan sesuai lingkup',
            'assignments.manage' => 'Mengelola penugasan',
            'work.review' => 'Memeriksa hasil pekerjaan',
            'documents.read' => 'Melihat dokumen sesuai lingkup',
            'documents.prepare' => 'Menyiapkan dokumen SPK/BAST',
            'documents.review' => 'Memeriksa dokumen SPK/BAST',
            'documents.approve' => 'Menyetujui dokumen sesuai kewenangan',
            'documents.sign' => 'Menandatangani dokumen sesuai kewenangan',
            'archives.read' => 'Membaca arsip sesuai lingkup',
            'reports.read' => 'Melihat laporan administrasi',
            'audit.read' => 'Melihat audit trail sesuai kewenangan',
        ];

        $permissions = collect($permissionNames)->mapWithKeys(fn (string $name, string $code) => [
            $code => Permission::updateOrCreate(['code' => $code], ['name' => $name]),
        ]);

        $roleNames = [
            'ADMIN_BPS' => 'Admin BPS',
            'PJK' => 'Penanggung Jawab Kegiatan',
            'OPERATOR' => 'Operator',
            'PEMERIKSA' => 'Pemeriksa',
            'PETUGAS_MITRA' => 'Petugas Mitra',
            'PEJABAT_PERSETUJU' => 'Pejabat Penyetuju',
            'PENANDATANGAN' => 'Penanda Tangan',
            'AUDITOR' => 'Pimpinan/Auditor',
        ];

        $rolePermissions = [
            'ADMIN_BPS' => array_keys($permissionNames),
            'PJK' => ['partners.read', 'activities.read', 'activities.manage', 'assignments.read', 'assignments.manage', 'work.review', 'documents.read', 'documents.prepare', 'documents.review', 'documents.approve', 'documents.sign', 'archives.read', 'reports.read'],
            'OPERATOR' => ['partners.read', 'partners.manage', 'activities.read', 'activities.manage', 'assignments.read', 'assignments.manage', 'documents.read', 'documents.prepare', 'archives.read'],
            'PEMERIKSA' => ['partners.read', 'partners.verify', 'activities.read', 'assignments.read', 'work.review', 'documents.read', 'documents.review', 'archives.read'],
            'PETUGAS_MITRA' => ['partners.read', 'activities.read', 'assignments.read', 'documents.read', 'archives.read'],
            'PEJABAT_PERSETUJU' => ['activities.read', 'assignments.read', 'documents.read', 'documents.review', 'documents.approve', 'archives.read'],
            'PENANDATANGAN' => ['documents.read', 'documents.sign', 'archives.read'],
            'AUDITOR' => ['partners.read', 'activities.read', 'assignments.read', 'documents.read', 'archives.read', 'reports.read', 'audit.read'],
        ];

        foreach ($roleNames as $code => $name) {
            $role = Role::updateOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
            $role->permissions()->sync($permissions->only($rolePermissions[$code] ?? [])->pluck('id')->all());
        }
    }
}
