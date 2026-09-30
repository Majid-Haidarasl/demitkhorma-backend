<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ActivityLogger;
use App\Services\OrderFulfillmentService;
use App\Services\OtpService;
use App\Services\ProductPricing;
use App\Services\ZarinpalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->orders()
            ->with('items')
            ->latest()
            ->paginate($request->safePerPage());

        return response()->json($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return response()->json(['data' => $order->load('items')]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertProfileComplete($request->user());

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.recipient_name' => ['required_without:address_id', 'string', 'max:100'],
            'shipping_address.phone' => ['required_without:address_id', 'string', 'regex:/^09\d{9}$/'],
            'shipping_address.province' => ['required_without:address_id', 'string', 'max:100'],
            'shipping_address.city' => ['required_without:address_id', 'string', 'max:100'],
            'shipping_address.address' => ['required_without:address_id', 'string', 'max:500'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:500'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        $shippingAddress = $this->resolveShippingAddress($request, $data);

        $order = DB::transaction(function () use ($request, $data, $shippingAddress) {
            $subtotal = 0;
            $lineItems = [];

            $productIds = collect($data['items'])->pluck('product_id')->unique()->values();
            $variantIds = collect($data['items'])->pluck('product_variant_id')->filter()->unique()->values();

            $products = Product::query()
                ->where('is_active', true)
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variants = $variantIds->isEmpty()
                ? collect()
                : ProductVariant::query()
                    ->whereIn('id', $variantIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

            $productsWithVariants = ProductVariant::query()
                ->whereIn('product_id', $productIds)
                ->distinct()
                ->pluck('product_id')
                ->flip();

            $flashDiscounts = ProductPricing::activeFlashDiscounts($productIds);

            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);
                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ['یکی از محصولات در دسترس نیست.'],
                    ]);
                }

                $variant = null;

                if (! empty($item['product_variant_id'])) {
                    $variant = $variants->get($item['product_variant_id']);
                    if (! $variant || (int) $variant->product_id !== (int) $product->id) {
                        throw ValidationException::withMessages([
                            'items' => ["وزن انتخاب‌شده برای «{$product->name_fa}» معتبر نیست."],
                        ]);
                    }
                } elseif ($productsWithVariants->has($product->id)) {
                    throw ValidationException::withMessages([
                        'items' => ["لطفاً وزن محصول «{$product->name_fa}» را انتخاب کنید."],
                    ]);
                }

                $unitPrice = ProductPricing::unitPrice(
                    $product,
                    $variant,
                    (int) ($flashDiscounts[$product->id] ?? 0)
                );
                $stock = $variant?->stock ?? $product->stock;

                if ($stock < $item['qty']) {
                    throw ValidationException::withMessages([
                        'items' => ["موجودی «{$product->name_fa}» کافی نیست."],
                    ]);
                }

                $subtotal += $unitPrice * $item['qty'];
                $lineItems[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'qty' => $item['qty'],
                    'price_snapshot' => $unitPrice,
                    'product_name' => $variant
                        ? "{$product->name_fa} — {$variant->weight_grams} گرم"
                        : $product->name_fa,
                ];
            }

            $shippingCost = 0;
            $discountAmount = 0;
            $couponId = null;
            $couponCode = null;

            if (! empty($data['coupon_code'])) {
                $coupon = app(\App\Services\CouponService::class)->findValid($data['coupon_code'], $subtotal);
                $coupon = Coupon::lockForUpdate()->findOrFail($coupon->id);

                if (! $coupon->isCurrentlyValid()) {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['کد تخفیف معتبر نیست یا منقضی شده است.'],
                    ]);
                }

                $discountAmount = $coupon->calculateDiscount($subtotal);
                $couponId = $coupon->id;
                $couponCode = $coupon->code;
                $coupon->increment('used_count');
            }

            $total = max(0, $subtotal - $discountAmount);

            $order = Order::create([
                'user_id' => $request->user()->id,
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'discount_amount' => $discountAmount,
                'coupon_id' => $couponId,
                'coupon_code' => $couponCode,
                'total' => $total,
                'status' => 'pending',
                'shipping_address' => $shippingAddress,
                'notes' => $data['notes'] ?? null,
            ]);

            $order->items()->createMany($lineItems);

            $this->persistCustomerAddress($request->user(), $shippingAddress);

            return $order->load('items');
        });

        return response()->json(['data' => $order], 201);
    }

    public function pay(Request $request, Order $order, ZarinpalService $zarinpal, OrderFulfillmentService $fulfillment): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== 'pending') {
            throw ValidationException::withMessages([
                'order' => ['این سفارش قابل پرداخت نیست.'],
            ]);
        }

        try {
            DB::transaction(function () use ($order, $fulfillment) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $fulfillment->assertStockAvailable($locked);
            });
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'order' => ['موجودی برخی اقلام کافی نیست. لطفاً سبد را بررسی کنید.'],
            ]);
        }

        $callbackUrl = url('/api/payment/zarinpal/callback');

        try {
            $result = $zarinpal->request(
                amount: $order->total,
                description: "سفارش #{$order->id} — دمیت خرما",
                callbackUrl: $callbackUrl,
                metadata: ['order_id' => $order->id],
            );
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'order' => ['شروع پرداخت ممکن نیست. سفارش شما ثبت شده و می‌توانید بعداً از حساب کاربری پرداخت کنید.'],
            ]);
        }

        $order->update(['zarinpal_authority' => $result['authority']]);
        Cache::put("zp_auth:{$result['authority']}", $order->id, now()->addHours(2));

        return response()->json([
            'data' => [
                'payment_url' => $result['payment_url'],
                'authority' => $result['authority'],
            ],
        ]);
    }

    private function assertProfileComplete($user): void
    {
        $user->loadCount('addresses');

        if (! $user->hasCompleteProfile()) {
            throw ValidationException::withMessages([
                'profile' => ['برای ثبت سفارش ابتدا نام، نام خانوادگی و حداقل یک آدرس را در پروفایل تکمیل کنید.'],
            ]);
        }
    }

    private function resolveShippingAddress(Request $request, array $data): array
    {
        if (! empty($data['address_id'])) {
            $address = Address::where('user_id', $request->user()->id)
                ->findOrFail($data['address_id']);

            return [
                'recipient_name' => $address->recipient_name,
                'phone' => $address->phone,
                'province' => $address->province,
                'city' => $address->city,
                'address' => $address->address,
                'postal_code' => $address->postal_code,
            ];
        }

        if (empty($data['shipping_address'])) {
            throw ValidationException::withMessages([
                'shipping_address' => ['آدرس ارسال الزامی است.'],
            ]);
        }

        return $data['shipping_address'];
    }

    private function persistCustomerAddress($user, array $shippingAddress): void
    {
        $payload = [
            'recipient_name' => $shippingAddress['recipient_name'] ?? '',
            'phone' => OtpService::normalizePhone((string) ($shippingAddress['phone'] ?? '')),
            'province' => $shippingAddress['province'] ?? '',
            'city' => $shippingAddress['city'] ?? '',
            'address' => $shippingAddress['address'] ?? '',
            'postal_code' => Address::normalizePostal($shippingAddress['postal_code'] ?? null),
        ];

        if ($payload['recipient_name'] === '' || $payload['address'] === '' || ! preg_match('/^09\d{9}$/', $payload['phone'])) {
            return;
        }

        $existing = $user->addresses()->where('is_default', true)->first()
            ?? $user->addresses()->latest()->first();

        if ($existing) {
            $existing->update($payload);

            return;
        }

        $user->addresses()->create([...$payload, 'is_default' => true]);
    }

    public function cancel(Request $request, Order $order, OrderFulfillmentService $fulfillment): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        try {
            DB::transaction(function () use ($order, $fulfillment) {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                if (! $locked->canBeCancelledByCustomer()) {
                    throw ValidationException::withMessages([
                        'order' => ['در این مرحله امکان لغو سفارش از سمت شما وجود ندارد.'],
                    ]);
                }

                $fulfillment->restoreAfterCancel($locked, $locked->status);
                $locked->update(['status' => 'cancelled']);
                ActivityLogger::log('order.cancelled_by_customer', $locked);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'order' => ['لغو سفارش ممکن نیست. لطفاً دوباره تلاش کنید.'],
            ]);
        }

        return response()->json([
            'message' => 'سفارش لغو شد.',
            'data' => $order->fresh(['items']),
        ]);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_if($order->user_id !== $request->user()->id, 403);
    }
}
