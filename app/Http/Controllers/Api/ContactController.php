<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\SiteSetting;
use App\Services\ShopMailService;
use App\Support\SafeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function store(Request $request, ShopMailService $mail): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:15'],
            'email' => ['nullable', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $message = ContactMessage::create([
            'name' => SafeInput::text($data['name']),
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'subject' => SafeInput::text($data['subject']),
            'message' => SafeInput::text($data['message']),
        ]);

        $notify = SiteSetting::get('admin_alert_email')
            ?: SiteSetting::get('email')
            ?: SiteSetting::get('contact_email');

        if (is_string($notify) && $notify !== '') {
            $mail->sendAlert(
                $notify,
                'پیام تماس جدید: '.$message->subject,
                "نام: {$message->name}\nموبایل: {$message->phone}\nایمیل: ".($message->email ?: '—')."\n\n{$message->message}"
            );
        }

        return response()->json(['message' => 'پیام شما دریافت شد.'], 201);
    }
}
