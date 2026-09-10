<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\FlashSale;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogSeeder extends Seeder
{
    /**
     * Real inventory only. Descriptions left empty for later update.
     * Admin can still create more products anytime.
     */
    public function run(): void
    {
        $categories = [
            ['name_fa' => 'خرمای ممتاز', 'slug' => 'premium-dates', 'sort_order' => 1],
            ['name_fa' => 'محصولات غیر خرمایی', 'slug' => 'non-date', 'sort_order' => 2],
            ['name_fa' => 'ارزش افزوده', 'slug' => 'value-added', 'sort_order' => 3],
            ['name_fa' => 'پک هدیه', 'slug' => 'gift-packages', 'sort_order' => 4],
        ];

        foreach ($categories as $cat) {
            Category::updateOrCreate(['slug' => $cat['slug']], $cat);
        }

        FlashSale::query()->delete();
        ProductImage::query()->delete();
        ProductVariant::query()->delete();

        if (Schema::hasTable('wishlists')) {
            DB::table('wishlists')->delete();
        }

        Product::query()->delete();

        /*
         * Price rule: variant_price = round(price_per_kg * weight_grams / 1000)
         * Except رولت خرمایی which has a fixed pack price.
         */
        $products = [
            [
                'category' => 'non-date',
                'name_fa' => 'ارده درجه ۱',
                'slug' => 'ardeh-grade-1',
                'description' => 'ارده ممتاز و درجه یک، تهیه شده از مرغوب‌ترین کنجد ایرانی. خالص، بدون مواد افزودنی و نرم و لطیف. یک بمب انرژی طبیعی و فوق‌العاده برای وعده صبحانه و عصرانه شما.',
                'packaging_color' => '#E8D5A3',
                'price_per_kg' => 705000,
                'is_bestseller' => true,
                'is_featured' => true,
                'variants' => [
                    ['weight_grams' => 250, 'stock' => 20],
                    ['weight_grams' => 500, 'stock' => 10],
                    ['weight_grams' => 1000, 'stock' => 5],
                ],
            ],
            [
                'category' => 'non-date',
                'name_fa' => 'کنجد',
                'slug' => 'konjed',
                'description' => 'کنجد باکیفیت، تمیز و دست‌چین شده. سرشار از کلسیم، آهن و روی. گزینه‌ای عالی برای تزیین نان و شیرینی، تهیه معجون‌های مقوی و استفاده در رژیم غذایی روزانه.',
                'packaging_color' => '#C4A35A',
                'price_per_kg' => 705000,
                'is_bestseller' => true,
                'variants' => [
                    ['weight_grams' => 250, 'stock' => 20],
                    ['weight_grams' => 500, 'stock' => 10],
                    ['weight_grams' => 1000, 'stock' => 5],
                ],
            ],
            [
                'category' => 'non-date',
                'name_fa' => 'شیره خرما',
                'slug' => 'shireh-khorma',
                'description' => 'شیره خرما طبیعی و غلیظ، تهیه شده از خرماهای شهددار و مرغوب. بدون شکر اضافه، یک جایگزین سالم برای شیرین‌کننده‌های مصنوعی و بهترین مکمل برای ارده.',
                'packaging_color' => '#8B4513',
                'price_per_kg' => 405000,
                'is_featured' => true,
                'variants' => [
                    ['weight_grams' => 250, 'stock' => 20],
                    ['weight_grams' => 500, 'stock' => 10],
                    ['weight_grams' => 1000, 'stock' => 5],
                ],
            ],
            [
                'category' => 'premium-dates',
                'name_fa' => 'خرمای کبکاب',
                'slug' => 'kabkab',
                'description' => 'خرمای کبکاب درجه یک؛ شیرین، گوشتی و پرشهد. این خرما با بافت نرم و طعم کاراملی خود، از محبوب‌ترین گزینه‌ها برای پذیرایی و مصرف روزانه در کنار چای است.',
                'packaging_color' => '#2563EB',
                'price_per_kg' => 355000,
                'is_bestseller' => true,
                'is_featured' => true,
                'variants' => [
                    ['weight_grams' => 1000, 'stock' => 200],
                ],
            ],
            [
                'category' => 'premium-dates',
                'name_fa' => 'خرمای شاهانی (حلاوی)',
                'slug' => 'shahani-halavi',
                'description' => 'خرمای شاهانی (حلاوی) با ظاهری کشیده، بافتی نرم و شیرینی ملایم و دلپذیر. خرمایی مجلسی، مقوی و سرشار از فیبر که انرژی روزانه شما را به خوبی تامین می‌کند.',
                'packaging_color' => '#EAB308',
                'price_per_kg' => 155000,
                'is_bestseller' => true,
                'variants' => [
                    ['weight_grams' => 1000, 'stock' => 100],
                ],
            ],
            [
                'category' => 'premium-dates',
                'name_fa' => 'خرمای مکتیو',
                'slug' => 'maktio',
                'description' => 'خرمای مکتیو با کیفیت صادراتی؛ دارای پوست نازک، بافت گوشتی و رطوبت متناسب. طعمی ناب و اصیل از نخلستان‌های جنوب، مخصوص کسانی که به دنبال کیفیت خاص هستند.',
                'packaging_color' => '#F5F5F5',
                'price_per_kg' => 205000,
                'variants' => [
                    ['weight_grams' => 1000, 'stock' => 50],
                ],
            ],
            [
                'category' => 'premium-dates',
                'name_fa' => 'خرمای بدون هسته',
                'slug' => 'bedone-haste',
                'description' => 'خرمای بدون هسته مرغوب؛ گزینه‌ای راحت و ایده‌آل برای تغذیه کودکان، تزیین انواع دسر، کیک و شیرینی‌پزی، بدون دردسرِ جدا کردن هسته و با همان طعم اصیل.',
                'packaging_color' => '#EAD9BB',
                'price_per_kg' => 155000,
                'is_featured' => true,
                'variants' => [
                    ['weight_grams' => 1000, 'stock' => 100],
                ],
            ],
            [
                'category' => 'premium-dates',
                'name_fa' => 'رطب',
                'slug' => 'rutab',
                'description' => 'رطب تازه و خنک با بافتی نرم، آبدار و ذوب‌شونده در دهان. محصولی دست‌چین و باکیفیت که طعم شیرین و خنکای نخلستان را به سفره‌های شما می‌آورد.',
                'packaging_color' => '#8B6914',
                'price_per_kg' => 305000,
                'is_featured' => true,
                'variants' => [
                    ['weight_grams' => 1000, 'stock' => 10],
                    ['weight_grams' => 2000, 'stock' => 10],
                ],
            ],
            [
                'category' => 'premium-dates',
                'name_fa' => 'قصب (خرمای زاهدی)',
                'slug' => 'ghasb',
                'description' => 'خرمای قصب (زاهدی)؛ خرمایی نیمه‌خشک، با قند طبیعی کنترل‌شده و ماندگاری بالا. گزینه‌ای عالی برای افراد دیابتی، ورزشکاران و همراهی همیشگی در جیب یا کیف شما.',
                'packaging_color' => '#DC2626',
                'price_per_kg' => 255000,
                'variants' => [
                    ['weight_grams' => 1000, 'stock' => 10],
                    ['weight_grams' => 2000, 'stock' => 10],
                ],
            ],
            [
                'category' => 'non-date',
                'name_fa' => 'حلوای ارده',
                'slug' => 'halva-ardeh',
                'description' => 'حلوای ارده سنتی و خوش‌طعم، تهیه شده از ارده خالص. بافتی نرم با شیرینی متناسب که انرژی و گرما را در طول روز به بدن شما هدیه می‌دهد.',
                'packaging_color' => '#D2B48C',
                'price_per_kg' => 255000,
                'variants' => [
                    ['weight_grams' => 300, 'stock' => 10],
                ],
            ],
            [
                'category' => 'value-added',
                'name_fa' => 'رولت خرمایی',
                'slug' => 'rolet-khormayi',
                'description' => 'رولت خرمایی؛ یک میان‌وعده مدرن، شیک و بسیار خوشمزه. ترکیبی خلاقانه و مقوی از خرما و ادویه‌های معطر، برش‌خورده و آماده برای یک پذیرایی لوکس و بی‌دردسر.',
                'packaging_color' => '#A0522D',
                'price_per_kg' => null,
                'is_bestseller' => true,
                'variants' => [
                    ['weight_grams' => 200, 'stock' => 50, 'price' => 70000],
                ],
            ],
        ];

        foreach ($products as $p) {
            $category = Category::where('slug', $p['category'])->first();
            $variants = [];

            foreach ($p['variants'] as $v) {
                $price = $v['price'] ?? (int) round($p['price_per_kg'] * $v['weight_grams'] / 1000);
                $variants[] = [
                    'weight_grams' => $v['weight_grams'],
                    'price' => $price,
                    'stock' => $v['stock'],
                    'sku' => strtoupper($p['slug']) . '-' . $v['weight_grams'],
                ];
            }

            $totalStock = array_sum(array_column($variants, 'stock'));
            $basePrice = min(array_column($variants, 'price'));

            $product = Product::create([
                'category_id' => $category->id,
                'name_fa' => $p['name_fa'],
                'slug' => $p['slug'],
                'description' => $p['description'] ?? null,
                'packaging_color' => $p['packaging_color'],
                'base_price' => $basePrice,
                'discount_percent' => 0,
                'stock' => $totalStock,
                'is_bestseller' => $p['is_bestseller'] ?? false,
                'is_featured' => $p['is_featured'] ?? false,
                'is_active' => true,
            ]);

            foreach ($variants as $variant) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    ...$variant,
                ]);
            }
        }
    }
}
