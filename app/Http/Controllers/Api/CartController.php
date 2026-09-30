<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductPricing;
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

        $products = $rows
            ->pluck('product')
            ->filter()
            ->values();
        ProductPricing::applyEffectiveDiscounts($products);

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

            if ($data['items'] === []) {
                return;
            }

            $productIds = collect($data['items'])->pluck('product_id')->unique()->values();
            $variantIds = collect($data['items'])->pluck('product_variant_id')->filter()->unique()->values();

            $products = Product::query()
                ->where('is_active', true)
                ->whereIn('id', $productIds)
                ->get(['id'])
                ->keyBy('id');

            $variants = $variantIds->isEmpty()
                ? collect()
                : ProductVariant::query()
                    ->whereIn('id', $variantIds)
                    ->get(['id', 'product_id'])
                    ->keyBy('id');

            $rows = [];
            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);
                if (! $product) {
                    continue;
                }

                $variantId = $item['product_variant_id'] ?? null;
                if ($variantId) {
                    $variant = $variants->get($variantId);
                    if (! $variant || (int) $variant->product_id !== (int) $product->id) {
                        throw ValidationException::withMessages([
                            'items' => ['وزن انتخاب‌شده برای محصول معتبر نیست.'],
                        ]);
                    }
                }

                $rows[] = [
                    'user_id' => $request->user()->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variantId,
                    'qty' => $item['qty'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                CartItem::insert($rows);
            }
        });

        return $this->index($request);
    }
}
