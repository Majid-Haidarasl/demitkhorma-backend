<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class BannerController extends Controller
{
    public function index(): JsonResponse
    {
        $now = Carbon::now();

        $banners = Banner::where('is_active', true)
            ->where('is_draft', false)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $banners]);
    }
}
