<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('username', 'majidadmin')->first()
            ?? User::where('role', 'admin')->where('phone', '09120000000')->first();

        if ($admin) {
            $admin->fill([
                'name' => 'مدیر',
                'username' => 'majidadmin',
                'phone' => '09120000000',
                'password' => '@Majid3510@',
                'phone_verified_at' => now(),
            ]);
            $admin->forceFill(['role' => 'admin'])->save();

            return;
        }

        $admin = User::create([
            'name' => 'مدیر',
            'username' => 'majidadmin',
            'phone' => '09120000000',
            'password' => '@Majid3510@',
            'phone_verified_at' => now(),
        ]);
        $admin->forceFill(['role' => 'admin'])->save();
    }
}
