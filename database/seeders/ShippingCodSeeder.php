<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class ShippingCodSeeder extends Seeder
{
    public function run(): void
    {
        $note = 'هزینه ارسال موقتاً در سایت گرفته نمی‌شود. مرسوله با پیک شرکت‌های اینترنتی ارسال می‌شود و کرایه هنگام تحویل با پیک تسویه می‌گردد.';

        SiteSetting::set('shipping_note', $note);

        Faq::updateOrCreate(
            ['question_fa' => 'هزینه ارسال چقدر است؟'],
            ['answer_fa' => $note, 'sort_order' => 3, 'is_active' => true]
        );

        Faq::updateOrCreate(
            ['question_fa' => 'زمان ارسال چقدر است؟'],
            [
                'answer_fa' => 'مرسوله با پیک شرکت‌های اینترنتی ارسال می‌شود. هزینه ارسال هنگام تحویل با پیک تسویه می‌گردد.',
                'sort_order' => 2,
                'is_active' => true,
            ]
        );
    }
}
