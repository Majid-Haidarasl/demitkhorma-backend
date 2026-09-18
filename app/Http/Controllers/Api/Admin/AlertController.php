<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Services\ActivityLogger;
use App\Services\OtpService;
use App\Services\ShopMailService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function lowStock(Request $request, SmsService $sms, ShopMailService $mail): JsonResponse
    {
        $threshold = (int) (SiteSetting::get('low_stock_threshold', 5) ?: 5);
        $products = Product::where('is_active', true)
            ->where('stock', '<=', $threshold)
            ->orderBy('stock')
            ->get(['id', 'name_fa', 'stock']);

        $phone = SiteSetting::get('admin_alert_phone');
        $email = SiteSetting::get('admin_alert_email');
        $names = $products->take(5)->map(fn ($p) => $p->name_fa.'('.$p->stock.')')->implode('، ');
        $text = "هشدار موجودی دمیت خرما: {$products->count()} محصول کم‌موجود. {$names}";

        $sendSms = $request->boolean('sms') && $phone && $products->isNotEmpty();
        $sendEmail = $request->boolean('email') && $email && $products->isNotEmpty();

        if ($sendSms) {
            $sms->sendText(OtpService::normalizePhone($phone), $text);
        }

        if ($sendEmail) {
            $mail->sendAlert((string) $email, 'هشدار موجودی کم — دمیت خرما', $text);
        }

        ActivityLogger::log('alert.low_stock_checked', null, [
            'count' => $products->count(),
            'sms' => (bool) $sendSms,
            'email' => (bool) $sendEmail,
        ]);

        $channelLabel = match (true) {
            $sendSms && $sendEmail => 'پیامک و ایمیل ارسال شد.',
            $sendSms => 'پیامک ارسال شد.',
            $sendEmail => 'ایمیل ارسال شد.',
            default => 'ارسال هشدار انجام نشد.',
        };

        $wantNotify = $request->boolean('sms') || $request->boolean('email');

        return response()->json([
            'data' => [
                'threshold' => $threshold,
                'count' => $products->count(),
                'products' => $products,
                'sms_sent' => (bool) $sendSms,
                'email_sent' => (bool) $sendEmail,
                'message' => ! $wantNotify
                    ? null
                    : ($products->isEmpty()
                        ? 'محصول کم‌موجودی برای ارسال هشدار یافت نشد.'
                        : (($request->boolean('sms') && ! $phone)
                            ? 'شماره هشدار مدیر در تنظیمات ثبت نشده است.'
                            : (($request->boolean('email') && ! $email)
                                ? 'ایمیل هشدار مدیر در تنظیمات ثبت نشده است.'
                                : $channelLabel))),
            ],
        ]);
    }
}
