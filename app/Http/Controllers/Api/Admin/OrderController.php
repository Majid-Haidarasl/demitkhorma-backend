<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ActivityLogger;
use App\Services\OrderFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::with([
            'user' => fn ($q) => $q->select('id', 'phone', 'name', 'first_name', 'last_name')->withCount('addresses'),
            'items',
        ])
            ->when($request->boolean('attention'), fn ($q) => $q->whereIn('status', Order::ATTENTION_STATUSES))
            ->when($request->filled('status') && ! $request->boolean('attention'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($inner) use ($s) {
                    $inner->where('id', $s)
                        ->orWhere('payment_ref', 'like', "%{$s}%")
                        ->orWhere('coupon_code', 'like', "%{$s}%")
                        ->orWhereHas('user', function ($uq) use ($s) {
                            $uq->where('phone', 'like', "%{$s}%")
                                ->orWhere('name', 'like', "%{$s}%")
                                ->orWhere('first_name', 'like', "%{$s}%")
                                ->orWhere('last_name', 'like', "%{$s}%");
                        });
                });
            })
            ->latest()
            ->paginate($request->safePerPage( 20));

        $orders->getCollection()->transform(function (Order $order) {
            return $order->makeVisible(['admin_notes', 'zarinpal_authority']);
        });

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json([
            'data' => $order->load([
                'user' => fn ($q) => $q->withCount('addresses'),
                'items.variant',
                'coupon',
            ])
                ->makeVisible(['admin_notes', 'zarinpal_authority']),
        ]);
    }

    public function updateStatus(Request $request, Order $order, OrderFulfillmentService $fulfillment): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled'])],
        ]);

        $newStatus = $data['status'];

        try {
            DB::transaction(function () use ($order, $newStatus, $fulfillment) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $old = $locked->status;

                if ($old === $newStatus) {
                    return;
                }

                $fulfillment->applyAdminStatus($locked, $newStatus);

                ActivityLogger::log('order.status_changed', $locked, [
                    'from' => $old,
                    'to' => $newStatus,
                ]);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'status' => ['تغییر وضعیت سفارش ممکن نیست: '.$e->getMessage()],
            ]);
        }

        return response()->json([
            'data' => $order->fresh()->load([
                'user' => fn ($q) => $q->withCount('addresses'),
                'items.variant',
                'coupon',
            ])
                ->makeVisible(['admin_notes', 'zarinpal_authority']),
        ]);
    }

    public function updateNotes(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $order->update(['admin_notes' => $data['admin_notes'] ?? null]);
        ActivityLogger::log('order.notes_updated', $order);

        return response()->json([
            'data' => $order->fresh()->load([
                'user' => fn ($q) => $q->withCount('addresses'),
                'items',
                'coupon',
            ])
                ->makeVisible(['admin_notes', 'zarinpal_authority']),
        ]);
    }
}
