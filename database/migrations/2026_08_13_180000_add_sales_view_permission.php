<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = config('permission.table_names.permissions');
        $roles = config('permission.table_names.roles');
        $rolePermissions = config('permission.table_names.role_has_permissions');
        $now = now();

        DB::table($permissions)->updateOrInsert(
            ['name' => 'sales.view', 'guard_name' => 'web'],
            [
                'group' => 'Penjualan',
                'label' => 'Lihat riwayat penjualan dan cetak ulang nota',
                'sort_order' => 999,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $permissionId = DB::table($permissions)
            ->where('name', 'sales.view')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table($roles)
            ->where('guard_name', 'web')
            ->whereIn('name', ['super-admin', 'admin', 'kasir'])
            ->pluck('id')
            ->each(fn (int $roleId) => DB::table($rolePermissions)->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]));
    }

    public function down(): void
    {
        $permissions = config('permission.table_names.permissions');
        $rolePermissions = config('permission.table_names.role_has_permissions');
        $permissionId = DB::table($permissions)
            ->where('name', 'sales.view')
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId) {
            DB::table($rolePermissions)->where('permission_id', $permissionId)->delete();
            DB::table($permissions)->where('id', $permissionId)->delete();
        }
    }
};
