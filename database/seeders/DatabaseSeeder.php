<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Faq;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
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
        } else {
            $admin = User::create([
                'name' => 'مدیر',
                'username' => 'majidadmin',
                'phone' => '09120000000',
                'password' => '@Majid3510@',
                'phone_verified_at' => now(),
            ]);
            $admin->forceFill(['role' => 'admin'])->save();
        }

        $this->call(CatalogSeeder::class);

        if (! Banner::query()->exists()) {
            Banner::create([
                'title' => 'خرمای ممتاز جنوب',
                'image' => '/images/banners/hero-1.jpg',
                'link' => '/shop',
                'is_active' => true,
                'sort_order' => 1,
            ]);
        }

        if (! Faq::query()->exists()) {
            Faq::insert([
                ['question_fa' => 'چگونه سفارش ثبت کنم؟', 'answer_fa' => 'محصول مورد نظر را به سبد خرید اضافه کرده و مراحل تسویه حساب را تکمیل کنید.', 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['question_fa' => 'زمان ارسال چقدر است؟', 'answer_fa' => 'سفارش‌ها معمولاً بین ۲ تا ۵ روز کاری به دست شما می‌رسند.', 'sort_order' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['question_fa' => 'آیا امکان مرجوعی وجود دارد؟', 'answer_fa' => 'در صورت مشکل در کیفیت محصول، تا ۴۸ ساعت پس از دریافت با پشتیبانی تماس بگیرید.', 'sort_order' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        $this->call(ContentSeeder::class);
        $this->call(ShippingCodSeeder::class);
    }
}
