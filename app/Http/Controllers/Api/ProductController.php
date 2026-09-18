<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\SafeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'images', 'variants'])
            ->where('is_active', true);

        if ($request->filled('category')) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $request->string('category')->toString()));
        }

        $search = SafeInput::likeContains($request->input('search'));
        if ($search !== null) {
            $query->where('name_fa', 'like', "%{$search}%");
        }

        if ($request->sort === 'bestseller') {
            $query->where('is_bestseller', true)->orderByDesc('id');
        } elseif ($request->sort === 'newest') {
            $query->orderByDesc('id');
        } elseif ($request->sort === 'featured') {
            $query->where('is_featured', true)->orderByDesc('id');
        } elseif ($request->sort === 'price_asc') {
            $query->orderBy('base_price');
        } elseif ($request->sort === 'price_desc') {
            $query->orderByDesc('base_price');
        } else {
            $query->orderByDesc('is_featured')->orderByDesc('id');
        }

        if ($request->boolean('discounted')) {
            $query->where('discount_percent', '>', 0);
        }

        $products = $query->paginate($request->safePerPage( 20));

        return response()->json($products);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::with(['category', 'images', 'variants'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json(['data' => $product]);
    }
}
