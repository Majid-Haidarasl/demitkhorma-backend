<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OrderFulfillmentService
{
    /**
     * Mark order paid and decrement stock.
     * Must be called inside an open DB transaction with the order row locked.
     * Coupon usage is reserved at order creation (not here).
     */
    public function markPaid(Order $order, ?string $paymentRef = null): void
    {
        $order->refresh();

        if ($order->status === 'paid') {
            CartItem::query()->where('user_id', $order->user_id)->delete();

            return;
        }

        if ($order->status !== 'pending' && $order->status !== 'processing') {
            throw new RuntimeException("Order #{$order->id} cannot be marked paid from status [{$order->status}].");
        }

        $order->loadMissing('items');

        $accounting = app(AccountingService::class);

        foreach ($order->items as $item) {
            $accounting->snapshotCost($item);
            $this->decrementStock($item->product_id, $item->product_variant_id, $item->qty, $order->id);
        }

        $payload = ['status' => 'paid'];
        if ($paymentRef !== null) {
            $payload['payment_ref'] = $paymentRef;
        }
        $order->update($payload);
        CartItem::query()->where('user_id', $order->user_id)->delete();
    }

    /**
     * Admin may set any status. Adjust stock and coupon usage accordingly.
     * Must be called inside an open DB transaction with the order row locked.
     */
    public function applyAdminStatus(Order $order, string $newStatus): void
    {
        $order->refresh();
        $old = $order->status;

        if ($old === $newStatus) {
            return;
        }

        $wasReserved = $this->stockIsReserved($old);
        $willReserve = $this->stockIsReserved($newStatus);

        $order->loadMissing('items');

        if (! $wasReserved && $willReserve) {
            $accounting = app(AccountingService::class);
            foreach ($order->items as $item) {
                $accounting->snapshotCost($item);
                $this->decrementStock($item->product_id, $item->product_variant_id, $item->qty, $order->id);
            }
            CartItem::query()->where('user_id', $order->user_id)->delete();
        } elseif ($wasReserved && ! $willReserve) {
            foreach ($order->items as $item) {
                $this->incrementStock($item->product_id, $item->product_variant_id, $item->qty);
            }
        }

        if ($old !== 'cancelled' && $newStatus === 'cancelled' && $order->coupon_id) {
            Coupon::where('id', $order->coupon_id)
                ->where('used_count', '>', 0)
                ->decrement('used_count');
        }

        if ($old === 'cancelled' && $newStatus !== 'cancelled' && $order->coupon_id) {
            Coupon::where('id', $order->coupon_id)->increment('used_count');
        }

        $order->update(['status' => $newStatus]);
    }

    public function stockIsReserved(string $status): bool
    {
        return in_array($status, ['paid', 'processing', 'shipped', 'delivered'], true);
    }

    /**
     * Restore stock / coupon when cancelling a fulfilled or coupon-reserved order.
     * Must be called inside an open DB transaction with the order row locked.
     */
    public function restoreAfterCancel(Order $order, string $previousStatus): void
    {
        if (in_array($previousStatus, ['paid', 'processing', 'shipped', 'delivered'], true)) {
            // Stock is only reserved when the order becomes paid (or paid via gateway).
            // processing/shipped/delivered are only reachable after paid in admin transitions.
            $order->loadMissing('items');

            foreach ($order->items as $item) {
                $this->incrementStock($item->product_id, $item->product_variant_id, $item->qty);
            }
        }

        // Coupon usage is reserved at order creation for any non-cancelled order.
        if ($order->coupon_id && $previousStatus !== 'cancelled') {
            Coupon::where('id', $order->coupon_id)
                ->where('used_count', '>', 0)
                ->decrement('used_count');
        }
    }

    public function assertStockAvailable(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if ($item->product_variant_id) {
                $variant = ProductVariant::lockForUpdate()->find($item->product_variant_id);
                if (! $variant || $variant->stock < $item->qty) {
                    throw new RuntimeException("Insufficient stock for variant #{$item->product_variant_id}");
                }
            } else {
                $product = Product::lockForUpdate()->find($item->product_id);
                if (! $product || $product->stock < $item->qty) {
                    throw new RuntimeException("Insufficient stock for product #{$item->product_id}");
                }
            }
        }
    }

    private function decrementStock(int $productId, ?int $variantId, int $qty, int $orderId): void
    {
        if ($variantId) {
            $variant = ProductVariant::lockForUpdate()->find($variantId);
            if (! $variant) {
                Log::critical('Paid order missing variant for stock decrement', [
                    'order_id' => $orderId,
                    'variant_id' => $variantId,
                ]);

                return;
            }

            if ($variant->stock < $qty) {
                Log::critical('Paid order oversold variant; clamping stock to 0', [
                    'order_id' => $orderId,
                    'variant_id' => $variantId,
                    'stock' => $variant->stock,
                    'qty' => $qty,
                ]);
                $variant->update(['stock' => 0]);
            } else {
                $variant->decrement('stock', $qty);
            }

            $this->syncProductStockFromVariants($productId);

            return;
        }

        $product = Product::lockForUpdate()->find($productId);
        if (! $product) {
            Log::critical('Paid order missing product for stock decrement', [
                'order_id' => $orderId,
                'product_id' => $productId,
            ]);

            return;
        }

        if ($product->stock < $qty) {
            Log::critical('Paid order oversold product; clamping stock to 0', [
                'order_id' => $orderId,
                'product_id' => $productId,
                'stock' => $product->stock,
                'qty' => $qty,
            ]);
            $product->update(['stock' => 0]);
        } else {
            $product->decrement('stock', $qty);
        }
    }

    private function incrementStock(int $productId, ?int $variantId, int $qty): void
    {
        if ($variantId) {
            $variant = ProductVariant::lockForUpdate()->find($variantId);
            if ($variant) {
                $variant->increment('stock', $qty);
                $this->syncProductStockFromVariants($productId);
            }

            return;
        }

        $product = Product::lockForUpdate()->find($productId);
        if ($product) {
            $product->increment('stock', $qty);
        }
    }

    private function syncProductStockFromVariants(int $productId): void
    {
        $product = Product::lockForUpdate()->find($productId);
        if (! $product) {
            return;
        }

        $sum = (int) ProductVariant::where('product_id', $productId)->sum('stock');
        $product->update(['stock' => $sum]);
    }
}
