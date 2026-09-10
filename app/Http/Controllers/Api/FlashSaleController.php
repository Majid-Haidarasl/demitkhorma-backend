<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlashSale;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class FlashSaleController extends Controller
{
    public function index(): JsonResponse
    {
        $sales = FlashSale::with(['product.category', 'product.images', 'product.variants'])
            ->where('is_active', true)
            ->where('is_draft', false)
            ->where('starts_at', '<=', Carbon::now())
            ->where('ends_at', '>=', Carbon::now())
            ->get();

        $endsAt = $sales->min('ends_at');

        return response()->json([
            'data' => $sales,
            'ends_at' => $endsAt?->toIso8601String(),
        ]);
    }
}
