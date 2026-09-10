<?php

use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OtpCode;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\OrderFulfillmentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('store:wipe-customers {--force}', function () {
    if (! $this->option('force')) {
        $this->error('برای تأیید حذف، --force را اضافه کنید.');

        return 1;
    }

    if (! app()->environment(['local', 'development'])) {
        $this->error('این دستور فقط در محیط local اجرا می‌شود.');

        return 1;
    }

    $ordersCount = Order::query()->count();
    $customersCount = User::query()->where('role', 'customer')->count();
    $fulfillment = app(OrderFulfillmentService::class);

    DB::transaction(function () use ($fulfillment) {
        Order::query()->orderBy('id')->each(function (Order $order) use ($fulfillment) {
            $locked = Order::query()->lockForUpdate()->find($order->id);
            if ($locked) {
                $fulfillment->restoreAfterCancel($locked, $locked->status);
            }
        });

        OrderItem::query()->delete();
        Order::query()->delete();

        $customerIds = User::query()->where('role', 'customer')->pluck('id');

        CartItem::query()->whereIn('user_id', $customerIds)->delete();
        Wishlist::query()->whereIn('user_id', $customerIds)->delete();
        Address::query()->whereIn('user_id', $customerIds)->delete();
        ActivityLog::query()->whereIn('user_id', $customerIds)->delete();
        ActivityLog::query()->where('subject_type', Order::class)->delete();
        PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $customerIds)
            ->delete();
        OtpCode::query()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', $customerIds)->delete();
        }

        User::query()->where('role', 'customer')->delete();
        Coupon::query()->update(['used_count' => 0]);
    });

    DB::statement('ALTER TABLE order_items AUTO_INCREMENT = 1');
    DB::statement('ALTER TABLE orders AUTO_INCREMENT = 1');
    $nextUserId = ((int) User::query()->max('id')) + 1;
    DB::statement("ALTER TABLE users AUTO_INCREMENT = {$nextUserId}");

    $this->info("حذف شد: {$ordersCount} سفارش و {$customersCount} مشتری. حساب ادمین باقی ماند.");
})->purpose('Delete all customers and orders for local testing');
