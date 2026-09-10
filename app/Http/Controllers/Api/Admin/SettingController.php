<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SiteSetting::query()->orderBy('key')->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'max:100'],
            'settings.*.value' => ['nullable', 'string'],
        ]);

        foreach ($data['settings'] as $row) {
            SiteSetting::set($row['key'], $row['value'] ?? '');
        }

        Cache::forget('settings.public');
        ActivityLogger::log('settings.updated');

        return response()->json([
            'message' => 'تنظیمات ذخیره شد.',
            'data' => SiteSetting::query()->orderBy('key')->get(),
        ]);
    }
}
