<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use App\Services\OrderFulfillmentService;
use App\Services\ZarinpalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function callback(Request $request, ZarinpalService $zarinpal, OrderFulfillmentService $fulfillment): RedirectResponse
    {
        $frontend = rtrim(config('services.frontend.url', 'http://localhost:5173'), '/');
        $authority = $request->query('Authority');
        $status = $request->query('Status');

        if (! $authority || $status !== 'OK') {
            return redirect("{$frontend}/order/failed");
        }

        $order = $this->findOrderByAuthority($authority);

        if (! $order) {
            return redirect("{$frontend}/order/failed");
        }

        if ($order->status === 'paid') {
            CartItem::query()->where('user_id', $order->user_id)->delete();

            return redirect("{$frontend}/order/success/{$order->id}");
        }

        if ($order->status !== 'pending') {
            return redirect("{$frontend}/order/failed?order={$order->id}");
        }

        try {
            // Verify with gateway first (idempotent: code 101 = already verified).
            $refId = $zarinpal->verify($order->total, $authority);

            DB::transaction(function () use ($order, $refId, $fulfillment) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();

                if (! $locked || $locked->status === 'paid') {
                    return;
                }

                if ($locked->status !== 'pending') {
                    return;
                }

                // Never throw after successful verify — customer has already paid.
                $fulfillment->markPaid($locked, (string) $refId);
            });
        } catch (\Throwable $e) {
            Log::error('Zarinpal verify failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return redirect("{$frontend}/order/failed?order={$order->id}");
        }

        return redirect("{$frontend}/order/success/{$order->id}");
    }

    private function findOrderByAuthority(string $authority): ?Order
    {
        $order = Order::where('zarinpal_authority', $authority)->first();

        if ($order) {
            return $order;
        }

        $orderId = Cache::get("zp_auth:{$authority}");

        return $orderId ? Order::find($orderId) : null;
    }
}
