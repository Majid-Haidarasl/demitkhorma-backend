<?php

namespace App\Services;

use App\Models\InventoryPurchase;
use App\Models\OperatingExpense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingService
{
    public const PAID_STATUSES = ['paid', 'processing', 'shipped', 'delivered'];

    public function recordPurchase(array $data, ?int $userId = null): InventoryPurchase
    {
        return DB::transaction(function () use ($data, $userId) {
            $product = Product::lockForUpdate()->findOrFail($data['product_id']);
            $variant = $this->resolveVariant($product, $data['product_variant_id'] ?? null);

            $qty = (int) $data['qty'];
            $unitCost = (int) $data['unit_cost'];

            $purchase = InventoryPurchase::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $qty * $unitCost,
                'supplier' => $data['supplier'] ?? null,
                'purchased_at' => $data['purchased_at'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->adjustStock($product, $variant, $qty);
            $this->recalcAvgCost($product->id, $variant?->id);

            ActivityLogger::log('accounting.purchase.created', $purchase);

            return $purchase->load(['product:id,name_fa', 'variant:id,weight_grams,sku']);
        });
    }

    public function updatePurchase(InventoryPurchase $purchase, array $data): InventoryPurchase
    {
        return DB::transaction(function () use ($purchase, $data) {
            $purchase = InventoryPurchase::lockForUpdate()->findOrFail($purchase->id);
            $product = Product::lockForUpdate()->findOrFail($purchase->product_id);
            $variant = $purchase->product_variant_id
                ? ProductVariant::lockForUpdate()->find($purchase->product_variant_id)
                : null;

            $qty = (int) ($data['qty'] ?? $purchase->qty);
            $unitCost = (int) ($data['unit_cost'] ?? $purchase->unit_cost);
            $qtyDelta = $qty - (int) $purchase->qty;

            if ($qtyDelta < 0) {
                $this->assertHasStock($product, $variant, abs($qtyDelta));
            }

            $purchase->update([
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $qty * $unitCost,
                'supplier' => $data['supplier'] ?? $purchase->supplier,
                'purchased_at' => $data['purchased_at'] ?? $purchase->purchased_at,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $purchase->notes,
            ]);

            if ($qtyDelta !== 0) {
                $this->adjustStock($product, $variant, $qtyDelta);
            }

            $this->recalcAvgCost($product->id, $variant?->id);
            ActivityLogger::log('accounting.purchase.updated', $purchase);

            return $purchase->fresh()->load(['product:id,name_fa', 'variant:id,weight_grams,sku']);
        });
    }

    public function deletePurchase(InventoryPurchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            $purchase = InventoryPurchase::lockForUpdate()->findOrFail($purchase->id);
            $product = Product::lockForUpdate()->findOrFail($purchase->product_id);
            $variant = $purchase->product_variant_id
                ? ProductVariant::lockForUpdate()->find($purchase->product_variant_id)
                : null;

            $this->assertHasStock($product, $variant, (int) $purchase->qty);
            $this->adjustStock($product, $variant, -1 * (int) $purchase->qty);

            $productId = $product->id;
            $variantId = $variant?->id;

            ActivityLogger::log('accounting.purchase.deleted', $purchase);
            $purchase->delete();

            $this->recalcAvgCost($productId, $variantId);
        });
    }

    public function snapshotCost(OrderItem $item): void
    {
        $this->snapshotCosts([$item]);
    }

    /**
     * Batch-fill cost_snapshot for order lines (same rules as snapshotCost).
     *
     * @param  iterable<OrderItem>  $items
     */
    public function snapshotCosts(iterable $items): void
    {
        $pending = collect($items)->filter(fn (OrderItem $item) => $item->cost_snapshot === null)->values();
        if ($pending->isEmpty()) {
            return;
        }

        $variantIds = $pending->pluck('product_variant_id')->filter()->unique()->values();
        $productIds = $pending->pluck('product_id')->filter()->unique()->values();

        $variantCosts = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::query()->whereIn('id', $variantIds)->pluck('avg_cost', 'id');
        $productCosts = $productIds->isEmpty()
            ? collect()
            : Product::query()->whereIn('id', $productIds)->pluck('avg_cost', 'id');

        foreach ($pending as $item) {
            $cost = 0;
            if ($item->product_variant_id) {
                $cost = (int) ($variantCosts[$item->product_variant_id] ?? 0);
            }
            if ($cost === 0 && $item->product_id) {
                $cost = (int) ($productCosts[$item->product_id] ?? 0);
            }

            $item->update(['cost_snapshot' => $cost]);
        }
    }

    public function summary(Carbon $from, Carbon $to): array
    {
        $orders = Order::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->whereBetween('created_at', [$from, $to]);

        $revenue = (int) (clone $orders)->sum('total');
        $discount = (int) (clone $orders)->sum('discount_amount');
        $ordersCount = (clone $orders)->count();

        $sold = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status', self::PAID_STATUSES)
            ->whereBetween('orders.created_at', [$from, $to]);

        $qtySold = (int) (clone $sold)->sum('order_items.qty');
        $cogs = (int) (clone $sold)->sum(DB::raw('order_items.qty * COALESCE(order_items.cost_snapshot, 0)'));
        $missingCostQty = (int) (clone $sold)->whereNull('order_items.cost_snapshot')->sum('order_items.qty');

        $purchases = InventoryPurchase::query()->whereBetween('purchased_at', [$from->toDateString(), $to->toDateString()]);
        $purchasesTotal = (int) (clone $purchases)->sum('total_cost');
        $purchasesQty = (int) (clone $purchases)->sum('qty');

        $expenses = OperatingExpense::query()->whereBetween('spent_at', [$from->toDateString(), $to->toDateString()]);
        $expensesTotal = (int) (clone $expenses)->sum('amount');
        $expensesByCategory = (clone $expenses)
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($v) => (int) $v)
            ->all();

        $grossProfit = $revenue - $cogs;
        $netProfit = $grossProfit - $expensesTotal;

        $topProducts = OrderItem::query()
            ->select(
                'order_items.product_id',
                'order_items.product_name',
                DB::raw('SUM(order_items.qty) as qty_sold'),
                DB::raw('SUM(order_items.qty * order_items.price_snapshot) as revenue'),
                DB::raw('SUM(order_items.qty * COALESCE(order_items.cost_snapshot, 0)) as cogs')
            )
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status', self::PAID_STATUSES)
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $revenue = (int) $row->revenue;
                $cogs = (int) $row->cogs;
                $profit = $revenue - $cogs;

                return [
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'qty_sold' => (int) $row->qty_sold,
                    'revenue' => $revenue,
                    'cogs' => $cogs,
                    'gross_profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                ];
            });

        $inventory = $this->inventorySnapshot();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'revenue' => $revenue,
            'discount' => $discount,
            'cogs' => $cogs,
            'missing_cost_qty' => $missingCostQty,
            'gross_profit' => $grossProfit,
            'gross_margin' => $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : 0,
            'expenses_total' => $expensesTotal,
            'expenses_by_category' => collect(OperatingExpense::CATEGORIES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'total' => (int) ($expensesByCategory[$key] ?? 0),
            ])->values()->all(),
            'net_profit' => $netProfit,
            'net_margin' => $revenue > 0 ? round($netProfit / $revenue * 100, 1) : 0,
            'orders_count' => $ordersCount,
            'qty_sold' => $qtySold,
            'avg_order' => $ordersCount > 0 ? (int) round($revenue / $ordersCount) : 0,
            'profit_per_order' => $ordersCount > 0 ? (int) round($netProfit / $ordersCount) : 0,
            'purchases_total' => $purchasesTotal,
            'purchases_qty' => $purchasesQty,
            'inventory_qty' => $inventory['qty'],
            'inventory_value' => $inventory['value'],
            'inventory_items' => $inventory['items'],
            'top_products' => $topProducts,
        ];
    }

    public function catalog(): array
    {
        return Product::query()
            ->with(['variants:id,product_id,weight_grams,sku,stock,avg_cost'])
            ->orderBy('name_fa')
            ->get(['id', 'name_fa', 'stock', 'avg_cost'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name_fa' => $p->name_fa,
                'stock' => (int) $p->stock,
                'avg_cost' => (int) $p->avg_cost,
                'variants' => $p->variants->map(fn (ProductVariant $v) => [
                    'id' => $v->id,
                    'weight_grams' => $v->weight_grams,
                    'sku' => $v->sku,
                    'stock' => (int) $v->stock,
                    'avg_cost' => (int) $v->avg_cost,
                ])->values(),
            ])
            ->all();
    }

    private function resolveVariant(Product $product, mixed $variantId): ?ProductVariant
    {
        $hasVariants = $product->variants()->exists();

        if ($hasVariants) {
            if (! $variantId) {
                throw ValidationException::withMessages([
                    'product_variant_id' => ['برای این محصول باید وزن/تنوع را انتخاب کنید.'],
                ]);
            }

            $variant = ProductVariant::lockForUpdate()
                ->where('product_id', $product->id)
                ->where('id', $variantId)
                ->first();

            if (! $variant) {
                throw ValidationException::withMessages([
                    'product_variant_id' => ['تنوع انتخاب‌شده معتبر نیست.'],
                ]);
            }

            return $variant;
        }

        if ($variantId) {
            throw ValidationException::withMessages([
                'product_variant_id' => ['این محصول تنوع ندارد.'],
            ]);
        }

        return null;
    }

    private function currentStock(Product $product, ?ProductVariant $variant): int
    {
        return $variant ? (int) $variant->stock : (int) $product->stock;
    }

    private function assertHasStock(Product $product, ?ProductVariant $variant, int $qty): void
    {
        if ($this->currentStock($product, $variant) < $qty) {
            throw ValidationException::withMessages([
                'qty' => ['موجودی فعلی کمتر از این مقدار است. ابتدا موجودی محصول را بررسی کنید.'],
            ]);
        }
    }

    private function adjustStock(Product $product, ?ProductVariant $variant, int $qtyDelta): void
    {
        if ($qtyDelta === 0) {
            return;
        }

        if ($variant) {
            $next = (int) $variant->stock + $qtyDelta;
            if ($next < 0) {
                throw ValidationException::withMessages([
                    'qty' => ['موجودی نمی‌تواند منفی شود.'],
                ]);
            }
            $variant->update(['stock' => $next]);
            $product->update([
                'stock' => (int) ProductVariant::where('product_id', $product->id)->sum('stock'),
            ]);

            return;
        }

        $next = (int) $product->stock + $qtyDelta;
        if ($next < 0) {
            throw ValidationException::withMessages([
                'qty' => ['موجودی نمی‌تواند منفی شود.'],
            ]);
        }
        $product->update(['stock' => $next]);
    }

    private function recalcAvgCost(int $productId, ?int $variantId): void
    {
        $query = InventoryPurchase::query()->where('product_id', $productId);
        if ($variantId) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        $qty = (int) $query->sum('qty');
        $cost = (int) $query->sum('total_cost');
        $avg = $qty > 0 ? (int) round($cost / $qty) : 0;

        if ($variantId) {
            ProductVariant::where('id', $variantId)->update(['avg_cost' => $avg]);
            $this->recalcProductAvgFromVariants($productId);

            return;
        }

        Product::where('id', $productId)->update(['avg_cost' => $avg]);
    }

    private function recalcProductAvgFromVariants(int $productId): void
    {
        $row = ProductVariant::query()
            ->where('product_id', $productId)
            ->selectRaw('SUM(stock) as qty, SUM(stock * avg_cost) as value')
            ->first();

        $qty = (int) ($row->qty ?? 0);
        $value = (int) ($row->value ?? 0);
        $avg = $qty > 0 ? (int) round($value / $qty) : (int) (ProductVariant::where('product_id', $productId)->avg('avg_cost') ?? 0);

        Product::where('id', $productId)->update(['avg_cost' => $avg]);
    }

    private function inventorySnapshot(): array
    {
        $variantTotals = ProductVariant::query()
            ->selectRaw('COALESCE(SUM(stock), 0) as qty, COALESCE(SUM(stock * avg_cost), 0) as value')
            ->first();

        $productTotals = Product::query()
            ->whereDoesntHave('variants')
            ->selectRaw('COALESCE(SUM(stock), 0) as qty, COALESCE(SUM(stock * avg_cost), 0) as value')
            ->first();

        $qty = (int) ($variantTotals->qty ?? 0) + (int) ($productTotals->qty ?? 0);
        $value = (int) ($variantTotals->value ?? 0) + (int) ($productTotals->value ?? 0);

        $variantRows = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->orderByDesc(DB::raw('product_variants.stock * product_variants.avg_cost'))
            ->limit(20)
            ->get([
                'product_variants.id as variant_id',
                'product_variants.product_id',
                'product_variants.weight_grams',
                'product_variants.stock',
                'product_variants.avg_cost',
                'products.name_fa as product_name',
            ])
            ->map(fn ($row) => [
                'id' => $row->product_id.'-'.$row->variant_id,
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'variant_id' => (int) $row->variant_id,
                'variant_label' => $row->weight_grams.' گرم',
                'stock' => (int) $row->stock,
                'avg_cost' => (int) $row->avg_cost,
                'value' => (int) $row->stock * (int) $row->avg_cost,
            ]);

        $productRows = Product::query()
            ->whereDoesntHave('variants')
            ->orderByDesc(DB::raw('stock * avg_cost'))
            ->limit(20)
            ->get(['id', 'name_fa', 'stock', 'avg_cost'])
            ->map(fn (Product $product) => [
                'id' => (string) $product->id,
                'product_id' => $product->id,
                'product_name' => $product->name_fa,
                'variant_id' => null,
                'variant_label' => null,
                'stock' => (int) $product->stock,
                'avg_cost' => (int) $product->avg_cost,
                'value' => (int) $product->stock * (int) $product->avg_cost,
            ]);

        $items = $variantRows
            ->concat($productRows)
            ->sortByDesc('value')
            ->take(20)
            ->values()
            ->all();

        return [
            'qty' => $qty,
            'value' => $value,
            'items' => $items,
        ];
    }
}
