<?php

namespace App\Services;

use App\Models\FlashSale;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProductPricing
{
    /**
     * Map of product_id => best active flash discount percent.
     *
     * @param  iterable<int>|null  $productIds  null = all currently active flash products
     * @return array<int, int>
     */
    public static function activeFlashDiscounts(?iterable $productIds = null): array
    {
        $query = FlashSale::query()
            ->where('is_active', true)
            ->where('is_draft', false)
            ->where('starts_at', '<=', Carbon::now())
            ->where('ends_at', '>=', Carbon::now());

        if ($productIds !== null) {
            $ids = collect($productIds)->filter()->unique()->values();
            if ($ids->isEmpty()) {
                return [];
            }
            $query->whereIn('product_id', $ids);
        }

        return $query
            ->get(['product_id', 'discount_percent'])
            ->groupBy('product_id')
            ->map(fn (Collection $rows) => (int) $rows->max('discount_percent'))
            ->all();
    }

    /**
     * @return Collection<int, int>
     */
    public static function activeFlashProductIds(): Collection
    {
        return collect(array_keys(self::activeFlashDiscounts()));
    }

    public static function effectiveDiscountPercent(Product $product, ?int $flashDiscount = null): int
    {
        $flash = $flashDiscount;
        if ($flash === null) {
            $map = self::activeFlashDiscounts([$product->id]);
            $flash = (int) ($map[$product->id] ?? 0);
        }

        $attrs = $product->getAttributes();
        if (array_key_exists('catalog_discount_percent', $attrs)) {
            $catalog = (int) $attrs['catalog_discount_percent'];
        } else {
            $raw = $product->getRawOriginal('discount_percent');
            $catalog = $raw !== null ? (int) $raw : (int) ($attrs['discount_percent'] ?? 0);
        }

        return max($catalog, (int) $flash);
    }

    public static function unitPrice(Product $product, ?ProductVariant $variant = null, ?int $flashDiscount = null): int
    {
        $base = $variant?->price ?? (int) $product->base_price;
        $discount = self::effectiveDiscountPercent($product, $flashDiscount);

        return (int) round($base * (1 - $discount / 100));
    }

    /**
     * Overwrite visible discount_percent with max(catalog, active flash)
     * so storefront/cart/mobile all show the same price as checkout.
     *
     * @param  Product|iterable<Product>|null  $products
     * @param  array<int, int>|null  $flashMap
     */
    public static function applyEffectiveDiscounts(mixed $products, ?array $flashMap = null): void
    {
        $list = self::normalizeProductList($products);
        if ($list->isEmpty()) {
            return;
        }

        $flashMap ??= self::activeFlashDiscounts($list->pluck('id'));

        foreach ($list as $product) {
            if (! $product instanceof Product) {
                continue;
            }

            $catalog = (int) ($product->getAttributes()['discount_percent'] ?? $product->discount_percent ?? 0);
            $flash = (int) ($flashMap[$product->id] ?? 0);
            $effective = max($catalog, $flash);

            // Keep original for admins / debugging without breaking clients.
            $product->setAttribute('catalog_discount_percent', $catalog);
            $product->setAttribute('flash_discount_percent', $flash);
            $product->setAttribute('discount_percent', $effective);
        }
    }

    /**
     * @return Collection<int, Product>
     */
    private static function normalizeProductList(mixed $products): Collection
    {
        if ($products === null) {
            return collect();
        }

        if ($products instanceof Product) {
            return collect([$products]);
        }

        if ($products instanceof Collection) {
            return $products->filter(fn ($p) => $p instanceof Product)->values();
        }

        if (is_iterable($products)) {
            return collect($products)->filter(fn ($p) => $p instanceof Product)->values();
        }

        return collect();
    }
}
