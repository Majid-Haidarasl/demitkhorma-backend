<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'site_name' => 'دمیت خرما',
            'site_tagline' => 'فروشگاه آنلاین خرمای ممتاز جنوب',
            'contact_email' => 'info@demitkhorma.ir',
            'contact_address' => 'شیراز',
            'contact_hours' => 'شنبه تا پنج‌شنبه، ۹ تا ۱۸',
            'instagram_url' => 'https://instagram.com/demitkhorma',
            'telegram_url' => 'https://t.me/demitkhorma',
            'shipping_note' => 'هزینه ارسال موقتاً در سایت گرفته نمی‌شود. مرسوله با پیک شرکت‌های اینترنتی ارسال می‌شود و کرایه هنگام تحویل با پیک تسویه می‌گردد.',
        ];

        foreach ($settings as $key => $value) {
            SiteSetting::set($key, $value);
        }

        $faqs = [
            ['question_fa' => 'چگونه سفارش ثبت کنم؟', 'answer_fa' => 'محصول را به سبد اضافه کنید، وارد حساب شوید، آدرس را وارد کنید و از درگاه زرین‌پال پرداخت کنید.', 'sort_order' => 1],
            ['question_fa' => 'زمان ارسال چقدر است؟', 'answer_fa' => 'مرسوله با پیک شرکت‌های اینترنتی ارسال می‌شود. هزینه ارسال هنگام تحویل با پیک تسویه می‌گردد.', 'sort_order' => 2],
            ['question_fa' => 'هزینه ارسال چقدر است؟', 'answer_fa' => 'هزینه ارسال موقتاً در سایت گرفته نمی‌شود. مرسوله با پیک شرکت‌های اینترنتی ارسال می‌شود و کرایه هنگام تحویل با پیک تسویه می‌گردد.', 'sort_order' => 3],
            ['question_fa' => 'آیا امکان مرجوعی وجود دارد؟', 'answer_fa' => 'در صورت مشکل در کیفیت یا آسیب بسته‌بندی، تا ۱ روز پس از دریافت با پشتیبانی تماس بگیرید.', 'sort_order' => 4],
            ['question_fa' => 'پرداخت چگونه انجام می‌شود؟', 'answer_fa' => 'پرداخت فقط از طریق درگاه امن زرین‌پال انجام می‌شود. اطلاعات کارت شما در سرور ما ذخیره نمی‌شود.', 'sort_order' => 5],
            ['question_fa' => 'چطور با پشتیبانی صحبت کنم؟', 'answer_fa' => 'از صفحه «تماس با ما» پیام بفرستید یا در تلگرام با ما در ارتباط باشید: t.me/demitkhorma', 'sort_order' => 6],
            ['question_fa' => 'چطور وضعیت سفارش را پیگیری کنم؟', 'answer_fa' => 'پس از ورود، از بخش «حساب کاربری» لیست سفارش‌ها و وضعیت هر کدام را مشاهده کنید.', 'sort_order' => 7],
        ];

        foreach ($faqs as $faq) {
            Faq::updateOrCreate(
                ['question_fa' => $faq['question_fa']],
                array_merge($faq, ['is_active' => true]),
            );
        }
    }
}
