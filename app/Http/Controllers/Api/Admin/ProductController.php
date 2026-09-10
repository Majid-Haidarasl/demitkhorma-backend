<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $threshold = (int) (SiteSetting::get('low_stock_threshold', 5) ?: 5);

        $products = Product::with(['category', 'variants', 'images'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($inner) use ($s) {
                    $inner->where('name_fa', 'like', "%{$s}%")
                        ->orWhere('slug', 'like', "%{$s}%");
                });
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->has('is_active') && $request->is_active !== '', fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->when($request->boolean('low_stock'), fn ($q) => $q->where('stock', '<=', $threshold))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($products);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->load(['category', 'variants', 'images'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($data) {
            $variants = $data['variants'] ?? [];
            $images = $data['images'] ?? [];
            unset($data['variants'], $data['images']);

            $data['slug'] = $data['slug'] ?? Str::slug($data['name_fa']);
            $product = Product::create($data);
            $this->syncVariants($product, $variants);
            $this->syncImages($product, $images);
            ActivityLogger::log('product.created', $product);

            return $product->load(['category', 'variants', 'images']);
        });

        return response()->json(['data' => $product], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product->id);

        $product = DB::transaction(function () use ($data, $product) {
            $variants = $data['variants'] ?? null;
            $images = $data['images'] ?? null;
            unset($data['variants'], $data['images']);

            // Product stock is derived from variants when variants are synced.
            if ($variants !== null) {
                unset($data['stock']);
            }

            $product->update($data);

            if ($variants !== null) {
                $this->syncVariants($product, $variants);
            }

            if ($images !== null) {
                $this->syncImages($product, $images);
            }

            ActivityLogger::log('product.updated', $product);

            return $product->load(['category', 'variants', 'images']);
        });

        return response()->json(['data' => $product]);
    }

    public function destroy(Product $product): JsonResponse
    {
        if (OrderItem::where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages([
                'product' => ['این محصول در سفارش‌ها استفاده شده و قابل حذف نیست. می‌توانید آن را غیرفعال کنید.'],
            ]);
        }

        ActivityLogger::log('product.deleted', $product);
        $product->delete();

        return response()->json(['message' => 'محصول حذف شد.']);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $slugRule = 'unique:products,slug';
        if ($ignoreId) {
            $slugRule .= ','.$ignoreId;
        }

        return $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name_fa' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', $slugRule],
            'description' => ['nullable', 'string'],
            'packaging_color' => ['nullable', 'string', 'max:7'],
            'base_price' => ['required', 'integer', 'min:0'],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'is_bestseller' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.weight_grams' => ['required_with:variants', 'integer', 'min:1'],
            'variants.*.price' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'images' => ['nullable', 'array'],
            'images.*.id' => ['nullable', 'integer'],
            'images.*.path' => ['required_with:images', 'string', 'max:500', 'regex:/^[A-Za-z0-9_\\/\\-]+\\.(jpe?g|png|webp|gif)$/i'],
            'images.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function syncVariants(Product $product, array $variants): void
    {
        $keepIds = [];

        foreach ($variants as $variant) {
            if (! empty($variant['id'])) {
                $existing = ProductVariant::where('product_id', $product->id)
                    ->where('id', $variant['id'])
                    ->first();

                if ($existing) {
                    $existing->update([
                        'weight_grams' => $variant['weight_grams'],
                        'price' => $variant['price'],
                        'sku' => $variant['sku'] ?? null,
                        'stock' => $variant['stock'] ?? 0,
                    ]);
                    $keepIds[] = $existing->id;
                    continue;
                }
            }

            $created = $product->variants()->create([
                'weight_grams' => $variant['weight_grams'],
                'price' => $variant['price'],
                'sku' => $variant['sku'] ?? null,
                'stock' => $variant['stock'] ?? 0,
            ]);
            $keepIds[] = $created->id;
        }

        $product->variants()->whereNotIn('id', $keepIds)->delete();

        $product->update([
            'stock' => (int) $product->variants()->sum('stock'),
        ]);
    }

    private function syncImages(Product $product, array $images): void
    {
        $keepIds = [];

        foreach ($images as $index => $image) {
            $payload = [
                'path' => $image['path'],
                'sort_order' => $image['sort_order'] ?? $index,
            ];

            if (! empty($image['id'])) {
                $existing = $product->images()->where('id', $image['id'])->first();
                if ($existing) {
                    $existing->update($payload);
                    $keepIds[] = $existing->id;
                    continue;
                }
            }

            $created = $product->images()->create($payload);
            $keepIds[] = $created->id;
        }

        $product->images()->whereNotIn('id', $keepIds)->delete();
    }
}
