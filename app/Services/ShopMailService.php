<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ShopMailService
{
    public function sendOtp(string $email, string $code, string $purpose = 'ورود / ثبت‌نام'): void
    {
        $purpose = e($purpose);
        $code = e($code);

        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('ایمیل نامعتبر است.');
        }

        Mail::html(
            $this->wrap(
                'کد تأیید',
                "<p style=\"margin:0 0 12px\">{$purpose}</p>
                <p style=\"margin:0 0 8px;font-size:13px;color:#555\">کد ۶ رقمی شما:</p>
                <p style=\"margin:0;font-size:28px;letter-spacing:6px;font-weight:700;color:#7A2E1F;direction:ltr\">{$code}</p>
                <p style=\"margin:16px 0 0;font-size:13px;color:#777\">این کد حدود ۶۰ ثانیه معتبر است. اگر این درخواست را شما نداده‌اید، پیام را نادیده بگیرید.</p>"
            ),
            function ($message) use ($email, $code) {
                $message->to($email)->subject("کد تأیید دمیت خرما: {$code}");
            }
        );
    }

    public function sendAlert(string $email, string $subject, string $body): void
    {
        $this->send($email, $subject, $this->wrap($subject, '<p style="margin:0;white-space:pre-wrap;line-height:1.8">'.e($body).'</p>'));
    }

    public function sendCustomerMessage(string $email, string $subject, string $body): void
    {
        $email = strtolower(trim($email));
        $subject = trim($subject) ?: 'پیام از دمیت خرما';

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('ایمیل نامعتبر است.');
        }

        Mail::html(
            $this->wrap($subject, '<p style="margin:0;white-space:pre-wrap;line-height:1.8">'.e($body).'</p>'),
            function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            }
        );
    }

    private function send(string $email, string $subject, string $html): void
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Mail::html($html, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
        } catch (Throwable $e) {
            Log::error('Shop mail send failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function wrap(string $title, string $inner): string
    {
        $title = e($title);

        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"></head>
<body style="margin:0;background:#F7F1E8;font-family:Tahoma,Arial,sans-serif">
  <div style="max-width:480px;margin:24px auto;background:#fff;border-radius:16px;padding:28px;color:#2A1C14">
    <p style="margin:0 0 4px;font-size:18px;font-weight:800">دمیت <span style="color:#C9A227">خرما</span></p>
    <p style="margin:0 0 20px;font-size:14px;color:#7A6A5A">{$title}</p>
    {$inner}
  </div>
</body>
</html>
HTML;
    }
}
