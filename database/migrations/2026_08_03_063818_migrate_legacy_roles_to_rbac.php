<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            return;
        }

        $roles = [
            'super_admin' => ['name' => 'super-admin', 'display_name' => 'Super Admin'],
            'admin' => ['name' => 'admin', 'display_name' => 'Admin'],
            'marketing' => ['name' => 'marketing', 'display_name' => 'Marketing'],
            'kasir' => ['name' => 'kasir', 'display_name' => 'Kasir'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name'], 'guard_name' => 'web'],
                [
                    'display_name' => $role['display_name'],
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $roleIds = DB::table('roles')->where('guard_name', 'web')->pluck('id', 'name');

        DB::table('users')->select(['id', 'role'])->orderBy('id')->chunk(100, function ($users) use ($roles, $roleIds) {
            foreach ($users as $user) {
                $roleName = $roles[$user->role]['name'] ?? 'kasir';

                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $roleIds[$roleName],
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
            }
        });

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable()->after('password');
        });

        $roleNames = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_type', User::class)
            ->pluck('roles.name', 'model_has_roles.model_id');

        $legacyNames = [
            'super-admin' => 'super_admin',
            'admin' => 'admin',
            'marketing' => 'marketing',
            'kasir' => 'kasir',
        ];

        foreach ($roleNames as $userId => $roleName) {
            DB::table('users')->where('id', $userId)->update([
                'role' => $legacyNames[$roleName] ?? $roleName,
            ]);
        }
    }
};
