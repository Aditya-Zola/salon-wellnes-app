<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 40)->nullable()->unique()->after('email');
            $table->string('email')->nullable()->change();
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $seededUsernames = [
                'superadmin@gmail.com' => 'owner.selesa',
                'admin@gmail.com' => 'admin.selesa',
                'marketing@gmail.com' => 'marketing.selesa',
                'kasir@gmail.com' => 'kasir.selesa',
            ];
            $generatedUsername = Str::of((string) ($user->email ?: $user->name))
                ->before('@')
                ->lower()
                ->replaceMatches('/[^a-z0-9_-]+/', '-')
                ->trim('-_')
                ->substr(0, 32)
                ->value();
            $base = $seededUsernames[$user->email] ?? $generatedUsername;
            $base = $base !== '' ? $base : "user-{$user->id}";
            $username = $base;
            $suffix = 2;

            while (DB::table('users')->where('username', $username)->where('id', '!=', $user->id)->exists()) {
                $username = Str::substr($base, 0, 36 - strlen((string) $suffix))."-{$suffix}";
                $suffix++;
            }

            DB::table('users')->where('id', $user->id)->update([
                'username' => $username,
                'updated_at' => now(),
            ]);

            $hasEmployeeProfile = DB::table('employees')->where('user_id', $user->id)->exists();
            if (! $hasEmployeeProfile) {
                DB::table('employees')->insert([
                    'user_id' => $user->id,
                    'code' => "USR-{$user->id}",
                    'name' => $user->name,
                    'position' => 'Pengguna sistem',
                    'specialty' => null,
                    'is_service_provider' => false,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNull('email')->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update([
                'email' => "{$user->username}@internal.selesa.local",
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
    }
};
