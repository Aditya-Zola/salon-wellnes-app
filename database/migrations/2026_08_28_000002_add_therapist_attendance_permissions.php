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

        $sortOrder = 35;
        foreach ([
            'therapist_attendance.view' => 'Lihat kehadiran terapis',
            'therapist_attendance.manage' => 'Kelola kehadiran terapis',
        ] as $name => $label) {
            DB::table($permissions)->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                [
                    'group' => 'Kehadiran Terapis',
                    'label' => $label,
                    'sort_order' => $sortOrder++,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $permissionIds = DB::table($permissions)
            ->where('guard_name', 'web')
            ->whereIn('name', ['therapist_attendance.view', 'therapist_attendance.manage'])
            ->pluck('id', 'name');

        DB::table($roles)
            ->where('guard_name', 'web')
            ->whereIn('name', ['super-admin', 'admin'])
            ->pluck('id')
            ->each(fn (int $roleId) => $permissionIds->each(
                fn (int $permissionId) => DB::table($rolePermissions)->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]),
            ));

        $marketingRoleId = DB::table($roles)
            ->where('guard_name', 'web')
            ->where('name', 'marketing')
            ->value('id');

        if ($marketingRoleId && $permissionIds->has('therapist_attendance.view')) {
            DB::table($rolePermissions)->updateOrInsert([
                'permission_id' => $permissionIds->get('therapist_attendance.view'),
                'role_id' => $marketingRoleId,
            ]);
        }
    }

    public function down(): void
    {
        $permissions = config('permission.table_names.permissions');
        $rolePermissions = config('permission.table_names.role_has_permissions');
        $permissionIds = DB::table($permissions)
            ->where('guard_name', 'web')
            ->whereIn('name', ['therapist_attendance.view', 'therapist_attendance.manage'])
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table($rolePermissions)->whereIn('permission_id', $permissionIds)->delete();
        DB::table($permissions)->whereIn('id', $permissionIds)->delete();
    }
};
