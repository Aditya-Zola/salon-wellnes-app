<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlSeeder extends Seeder
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $permissionGroups = [
        'Dashboard' => [
            'dashboard.view' => 'Lihat dashboard',
        ],
        'Reservasi' => [
            'reservations.view' => 'Lihat reservasi',
            'reservations.create' => 'Tambah reservasi',
            'reservations.update' => 'Ubah reservasi',
            'reservations.override_conflict' => 'Override benturan jadwal',
            'reservations.override_price' => 'Override harga reservasi',
            'reservations.delete' => 'Hapus reservasi',
        ],
        'Pegawai' => [
            'employees.view' => 'Lihat data pegawai',
            'employees.create' => 'Tambah pegawai',
            'employees.update' => 'Ubah pegawai',
        ],
        'Kehadiran Terapis' => [
            'therapist_attendance.view' => 'Lihat kehadiran terapis',
            'therapist_attendance.manage' => 'Kelola kehadiran terapis',
        ],
        'Kasir' => [
            'cashier.view' => 'Lihat kasir',
            'cashier.process' => 'Proses transaksi',
            'cashier.refund' => 'Proses pengembalian dana',
        ],
        'Penjualan' => [
            'sales.view' => 'Lihat riwayat penjualan dan cetak ulang nota',
        ],
        'Treatment' => [
            'treatments.view' => 'Lihat treatment',
            'treatments.create' => 'Tambah treatment',
            'treatments.update' => 'Ubah treatment',
            'treatments.delete' => 'Hapus treatment',
        ],
        'Membership' => [
            'memberships.view' => 'Lihat membership',
            'memberships.manage' => 'Kelola membership dan promo',
        ],
        'Produk & Stok' => [
            'products.view' => 'Lihat produk dan stok',
            'products.create' => 'Tambah produk',
            'products.update' => 'Ubah produk dan stok',
            'products.delete' => 'Hapus produk',
            'products.stocktake' => 'Melakukan stok opname',
        ],
        'Keuangan' => [
            'finance.view' => 'Lihat keuangan',
            'finance.manage' => 'Kelola transaksi keuangan',
        ],
        'Penggajian' => [
            'payroll.view' => 'Lihat penggajian',
            'payroll.manage' => 'Kelola dan tutup penggajian',
        ],
        'Log Aktivitas' => [
            'activity.view' => 'Lihat log aktivitas',
        ],
        'Pengaturan' => [
            'settings.manage' => 'Kelola pengaturan penjualan dan metode pembayaran',
        ],
        'Hak Akses - Peran' => [
            'access.roles.view' => 'Lihat daftar peran',
            'access.roles.manage' => 'Tambah, ubah, dan hapus peran',
        ],
        'Hak Akses - Pengguna' => [
            'access.users.view' => 'Lihat daftar pengguna',
            'access.users.manage' => 'Tambah, ubah, dan hapus pengguna',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $sortOrder = 1;

        foreach ($this->permissionGroups as $group => $permissions) {
            foreach ($permissions as $name => $label) {
                Permission::query()->updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['group' => $group, 'label' => $label, 'sort_order' => $sortOrder++]
                );
            }
        }

        $roles = [
            'super-admin' => 'Super Admin',
            'admin' => 'Admin',
            'marketing' => 'Marketing',
            'kasir' => 'Kasir',
        ];

        foreach ($roles as $name => $displayName) {
            Role::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['display_name' => $displayName, 'is_system' => true]
            );
        }

        $allPermissions = Permission::query()->pluck('name')->all();

        Role::findByName('super-admin')->syncPermissions($allPermissions);
        Role::findByName('admin')->syncPermissions(array_values(array_filter(
            $allPermissions,
            fn (string $permission) => ! str_starts_with($permission, 'access.')
        )));
        Role::findByName('marketing')->syncPermissions([
            'dashboard.view',
            'employees.view',
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'therapist_attendance.view',
            'treatments.view',
            'memberships.view',
            'memberships.manage',
            'products.view',
            'products.create',
            'products.update',
            'products.stocktake',
        ]);
        Role::findByName('kasir')->syncPermissions([
            'dashboard.view',
            'reservations.view',
            'cashier.view',
            'cashier.process',
            'sales.view',
            'treatments.view',
            'memberships.view',
            'products.view',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
