<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
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

    public function suggest(Request $request): JsonResponse
    {
        $raw = $this->normalizeFa(trim((string) $request->input('q', '')));
        $term = SafeInput::likeContains($raw, 40);

        if ($term === null || mb_strlen($raw) < 2) {
            return response()->json(['data' => []]);
        }

        $products = Product::query()
            ->with('images')
            ->where('is_active', true)
            ->where('name_fa', 'like', "%{$term}%")
            ->orderByRaw('CASE WHEN name_fa LIKE ? THEN 0 ELSE 1 END', ["{$term}%"])
            ->orderByDesc('is_featured')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'name_fa', 'slug', 'base_price', 'discount_percent']);

        $categories = Category::query()
            ->where('name_fa', 'like', "%{$term}%")
            ->orderByRaw('CASE WHEN name_fa LIKE ? THEN 0 ELSE 1 END', ["{$term}%"])
            ->orderBy('sort_order')
            ->limit(4)
            ->get(['id', 'name_fa', 'slug']);

        $data = [];

        foreach ($categories as $category) {
            $data[] = [
                'type' => 'category',
                'id' => $category->id,
                'name_fa' => $category->name_fa,
                'slug' => $category->slug,
                'image' => null,
            ];
        }

        foreach ($products as $product) {
            $data[] = [
                'type' => 'product',
                'id' => $product->id,
                'name_fa' => $product->name_fa,
                'slug' => $product->slug,
                'image' => $product->images->first()?->path,
                'base_price' => (int) $product->base_price,
                'discount_percent' => (int) $product->discount_percent,
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::with(['category', 'images', 'variants'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json(['data' => $product]);
    }

    private function normalizeFa(string $value): string
    {
        return str_replace(['ك', 'ي', 'ة'], ['ک', 'ی', 'ه'], $value);
    }
}
