<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = $request->user()
            ->cartItems()
            ->with(['product.category', 'product.images', 'product.variants', 'variant'])
            ->latest()
            ->get();

        $data = $rows
            ->filter(fn (CartItem $row) => $row->product && $row->product->is_active)
            ->map(fn (CartItem $row) => [
                'qty' => $row->qty,
                'product' => $row->product,
                'variant' => $row->variant,
            ])
            ->values();

        return response()->json(['data' => $data]);
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['present', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        DB::transaction(function () use ($request, $data) {
            $request->user()->cartItems()->delete();

            foreach ($data['items'] as $item) {
                $product = Product::where('is_active', true)->find($item['product_id']);
                if (! $product) {
                    continue;
                }

                $variantId = $item['product_variant_id'] ?? null;
                if ($variantId) {
                    $variant = ProductVariant::where('product_id', $product->id)->find($variantId);
                    if (! $variant) {
                        throw ValidationException::withMessages([
                            'items' => ['وزن انتخاب‌شده برای محصول معتبر نیست.'],
                        ]);
                    }
                }

                CartItem::create([
                    'user_id' => $request->user()->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variantId,
                    'qty' => $item['qty'],
                ]);
            }
        });

        return $this->index($request);
    }
}
