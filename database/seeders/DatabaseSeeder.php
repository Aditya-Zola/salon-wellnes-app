<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AccessControlSeeder::class);
        DB::table('sale_settings')->updateOrInsert(
            ['key' => 'invoice_prefix'],
            ['value' => 'INV', 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('sale_settings')->updateOrInsert(
            ['key' => 'salon_address'],
            ['value' => 'Jl. Telaga Asmara, Tlogosari Kulon, Semarang', 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('sale_settings')->updateOrInsert(
            ['key' => 'salon_whatsapp'],
            ['value' => '081128702019', 'created_at' => now(), 'updated_at' => now()],
        );

        $accounts = [
            ['name' => 'Owner Selesa', 'username' => 'owner.selesa', 'email' => 'superadmin@gmail.com', 'role' => 'super_admin'],
            ['name' => 'Admin Selesa', 'username' => 'admin.selesa', 'email' => 'admin@gmail.com', 'role' => 'admin'],
            ['name' => 'Marketing Selesa', 'username' => 'marketing.selesa', 'email' => 'marketing@gmail.com', 'role' => 'marketing'],
            ['name' => 'Kasir Selesa', 'username' => 'kasir.selesa', 'email' => 'kasir@gmail.com', 'role' => 'kasir'],
        ];

        foreach ($accounts as $account) {
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'username' => $account['username'],
                    'password' => 'password',
                ]
            );

            if ($user->name !== $account['name'] || $user->username !== $account['username']) {
                $user->update(['name' => $account['name'], 'username' => $account['username']]);
            }

            $user->syncRoles($account['role'] === 'super_admin' ? 'super-admin' : $account['role']);

            Employee::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'code' => "USR-{$user->id}",
                    'name' => $user->name,
                    'position' => 'Pengguna sistem',
                    'is_service_provider' => false,
                    'active' => true,
                ],
            );
        }

        $this->call(SalonOperationSeeder::class);
    }
}
