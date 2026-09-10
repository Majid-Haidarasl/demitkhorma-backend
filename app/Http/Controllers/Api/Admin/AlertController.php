<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Services\ActivityLogger;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function lowStock(Request $request, SmsService $sms): JsonResponse
    {
        $threshold = (int) (SiteSetting::get('low_stock_threshold', 5) ?: 5);
        $products = Product::where('is_active', true)
            ->where('stock', '<=', $threshold)
            ->orderBy('stock')
            ->get(['id', 'name_fa', 'stock']);

        $phone = SiteSetting::get('admin_alert_phone');
        $sendSms = $request->boolean('sms') && $phone && $products->isNotEmpty();

        if ($sendSms) {
            $names = $products->take(5)->map(fn ($p) => $p->name_fa.'('.$p->stock.')')->implode('، ');
            $message = "هشدار موجودی دمیت خرما: {$products->count()} محصول کم‌موجود. {$names}";
            $sms->sendText(OtpService::normalizePhone($phone), $message);
        }

        ActivityLogger::log('alert.low_stock_checked', null, [
            'count' => $products->count(),
            'sms' => (bool) $sendSms,
        ]);

        return response()->json([
            'data' => [
                'threshold' => $threshold,
                'count' => $products->count(),
                'products' => $products,
                'sms_sent' => (bool) $sendSms,
                'message' => ! $request->boolean('sms')
                    ? null
                    : (! $phone
                        ? 'شماره هشدار مدیر در تنظیمات ثبت نشده است.'
                        : ($products->isEmpty()
                            ? 'محصول کم‌موجودی برای ارسال پیامک یافت نشد.'
                            : ($sendSms ? 'پیامک ارسال شد.' : 'ارسال پیامک انجام نشد.'))),
            ],
        ]);
    }
}
