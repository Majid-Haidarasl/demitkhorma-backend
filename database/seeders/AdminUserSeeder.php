<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) env('ADMIN_SEED_PASSWORD', 'ChangeMeNow!');

        $admin = User::where('username', 'majidadmin')->first()
            ?? User::where('role', 'admin')->where('phone', '09120000000')->first();

        if ($admin) {
            $admin->fill([
                'name' => 'مدیر',
                'username' => 'majidadmin',
                'phone' => '09120000000',
                'password' => $password,
            ]);
            $admin->forceFill([
                'role' => 'admin',
                'phone_verified_at' => now(),
            ])->save();

            return;
        }

        $admin = User::create([
            'name' => 'مدیر',
            'username' => 'majidadmin',
            'phone' => '09120000000',
            'password' => $password,
        ]);
        $admin->forceFill([
            'role' => 'admin',
            'phone_verified_at' => now(),
        ])->save();
    }
}
