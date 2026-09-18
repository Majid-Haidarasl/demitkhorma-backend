<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SiteSetting::query()
                ->whereIn('key', SiteSetting::WRITABLE_KEYS)
                ->orderBy('key')
                ->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array', 'max:40'],
            'settings.*.key' => ['required', 'string', Rule::in(SiteSetting::WRITABLE_KEYS)],
            'settings.*.value' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($data['settings'] as $row) {
            $key = $row['key'];
            $value = trim((string) ($row['value'] ?? ''));

            if (in_array($key, SiteSetting::URL_KEYS, true) && $value !== '') {
                $lower = strtolower($value);
                if (str_contains($lower, 'javascript:') || str_contains($lower, 'data:') || str_contains($lower, 'vbscript:')) {
                    throw ValidationException::withMessages([
                        'settings' => ["آدرس «{$key}» نامعتبر است."],
                    ]);
                }
                if (str_starts_with($lower, 'https://') && ! preg_match('/^https:\\/\\/[A-Za-z0-9.-]+(:\\d+)?(\\/[^\\s]*)?$/', $value)) {
                    throw ValidationException::withMessages([
                        'settings' => ["آدرس «{$key}» نامعتبر است."],
                    ]);
                }
                if (! str_starts_with($lower, 'https://') && ! preg_match('/^[A-Za-z0-9_@.\\/-]+$/', $value)) {
                    throw ValidationException::withMessages([
                        'settings' => ["آدرس «{$key}» نامعتبر است."],
                    ]);
                }
            }

            if ($key === 'admin_alert_phone' && $value !== '' && ! preg_match('/^09\\d{9}$/', $value)) {
                throw ValidationException::withMessages([
                    'settings' => ['موبایل هشدار مدیر نامعتبر است.'],
                ]);
            }

            if ($key === 'admin_alert_email' && $value !== '') {
                $value = strtolower($value);
                if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages([
                        'settings' => ['ایمیل هشدار مدیر نامعتبر است.'],
                    ]);
                }
            }

            if ($key === 'low_stock_threshold') {
                $value = (string) max(0, min(9999, (int) $value));
            }

            SiteSetting::set($key, $value);
        }

        Cache::forget('settings.public');
        ActivityLogger::log('settings.updated');

        return response()->json([
            'message' => 'تنظیمات ذخیره شد.',
            'data' => SiteSetting::query()
                ->whereIn('key', SiteSetting::WRITABLE_KEYS)
                ->orderBy('key')
                ->get(),
        ]);
    }
}
