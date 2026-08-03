<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AccessControlSeeder::class);

        User::whereIn('email', [
            'owner@selesa.test', 'admin@selesa.test',
            'marketing@selesa.test', 'kasir@selesa.test',
            'admin@gmail.com',
        ])->delete();

        $accounts = [
            ['name' => 'Owner Selesa', 'email' => 'superadmin@gmail.com', 'role' => 'super_admin'],
            ['name' => 'Admin Selesa', 'email' => 'admin@gmail.com', 'role' => 'admin'],
            ['name' => 'Marketing Selesa', 'email' => 'marketing@gmail.com', 'role' => 'marketing'],
            ['name' => 'Kasir Selesa', 'email' => 'kasir@gmail.com', 'role' => 'kasir'],
        ];

        foreach ($accounts as $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => 'password',
                ]
            );

            $user->syncRoles($account['role'] === 'super_admin' ? 'super-admin' : $account['role']);
        }

        $this->call(SalonOperationSeeder::class);
    }
}
