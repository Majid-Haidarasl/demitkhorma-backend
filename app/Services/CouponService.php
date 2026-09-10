<?php

namespace App\Services;

use App\Models\Coupon;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function findValid(string $code, int $subtotal): Coupon
    {
        $coupon = Coupon::whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first();

        if (! $coupon || ! $coupon->isCurrentlyValid()) {
            throw ValidationException::withMessages([
                'coupon_code' => ['کد تخفیف معتبر نیست یا منقضی شده است.'],
            ]);
        }

        if ($subtotal < $coupon->min_order) {
            throw ValidationException::withMessages([
                'coupon_code' => ['حداقل مبلغ سفارش برای این کد '.number_format($coupon->min_order).' تومان است.'],
            ]);
        }

        $discount = $coupon->calculateDiscount($subtotal);

        if ($discount <= 0) {
            throw ValidationException::withMessages([
                'coupon_code' => ['این کد برای سفارش شما قابل اعمال نیست.'],
            ]);
        }

        return $coupon;
    }
}
