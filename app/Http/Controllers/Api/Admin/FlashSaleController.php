<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FlashSale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlashSaleController extends Controller
{
    public function index(): JsonResponse
    {
        $sales = FlashSale::with('product')->latest()->get();

        return response()->json(['data' => $sales]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $sale = FlashSale::create($data);

        return response()->json(['data' => $sale->load('product')], 201);
    }

    public function update(Request $request, FlashSale $flashSale): JsonResponse
    {
        $flashSale->update($this->validated($request));

        return response()->json(['data' => $flashSale->fresh()->load('product')]);
    }

    public function destroy(FlashSale $flashSale): JsonResponse
    {
        $flashSale->delete();

        return response()->json(['message' => 'فلش‌سیل حذف شد.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'discount_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'is_draft' => ['nullable', 'boolean'],
        ]);
    }
}
