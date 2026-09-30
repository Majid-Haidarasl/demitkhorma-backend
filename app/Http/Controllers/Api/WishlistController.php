<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Wishlist;
use App\Services\ProductPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = $request->user()
            ->wishlistProducts()
            ->with(['category', 'images', 'variants'])
            ->where('is_active', true)
            ->latest('wishlists.created_at')
            ->get();

        ProductPricing::applyEffectiveDiscounts($products);

        return response()->json(['data' => $products]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $product = Product::where('is_active', true)->findOrFail($data['product_id']);

        Wishlist::firstOrCreate([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
        ]);

        return response()->json(['message' => 'به علاقه‌مندی‌ها اضافه شد.']);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        Wishlist::where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->delete();

        return response()->json(['message' => 'از علاقه‌مندی‌ها حذف شد.']);
    }
}
