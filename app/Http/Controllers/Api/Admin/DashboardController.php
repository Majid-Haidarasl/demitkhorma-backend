<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $paidStatuses = ['paid', 'processing', 'shipped', 'delivered'];
        $threshold = (int) (SiteSetting::get('low_stock_threshold', 5) ?: 5);

        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();
        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : now()->subDays(6)->startOfDay();

        $rangeOrders = Order::whereIn('status', $paidStatuses)->whereBetween('created_at', [$from, $to]);

        $salesDays = (clone $rangeOrders)
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('SUM(total) as revenue'), DB::raw('COUNT(*) as orders'))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $rangeAgg = (clone $rangeOrders)
            ->selectRaw('COALESCE(SUM(total), 0) as revenue, COUNT(*) as orders')
            ->first();

        $chart = [];
        $cursor = $from->copy()->startOfDay();
        while ($cursor <= $to) {
            $day = $cursor->toDateString();
            $row = $salesDays->get($day);
            $chart[] = [
                'date' => $day,
                'label' => $cursor->format('m/d'),
                'revenue' => (int) ($row->revenue ?? 0),
                'orders' => (int) ($row->orders ?? 0),
            ];
            $cursor->addDay();
            if (count($chart) > 90) {
                break;
            }
        }

        $productStats = Product::query()
            ->selectRaw('COUNT(*) as products_count')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_products')
            ->selectRaw('SUM(CASE WHEN is_active = 1 AND stock <= ? THEN 1 ELSE 0 END) as low_stock_count', [$threshold])
            ->first();

        $ordersByStatus = Order::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $ordersCount = (int) $ordersByStatus->sum();
        $attentionOrders = 0;
        foreach (Order::ATTENTION_STATUSES as $status) {
            $attentionOrders += (int) ($ordersByStatus[$status] ?? 0);
        }

        $today = now()->toDateString();
        $paidRevenue = Order::query()
            ->whereIn('status', $paidStatuses)
            ->selectRaw('COALESCE(SUM(total), 0) as all_time')
            ->selectRaw('COALESCE(SUM(CASE WHEN DATE(created_at) = ? THEN total ELSE 0 END), 0) as today', [$today])
            ->selectRaw('COALESCE(SUM(CASE WHEN YEAR(created_at) = ? AND MONTH(created_at) = ? THEN total ELSE 0 END), 0) as month', [
                now()->year,
                now()->month,
            ])
            ->first();

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'low_stock_threshold' => $threshold,
                'products_count' => (int) ($productStats->products_count ?? 0),
                'active_products' => (int) ($productStats->active_products ?? 0),
                'low_stock_count' => (int) ($productStats->low_stock_count ?? 0),
                'orders_count' => $ordersCount,
                'pending_orders' => (int) ($ordersByStatus['pending'] ?? 0),
                'attention_orders' => $attentionOrders,
                'paid_orders' => (int) ($ordersByStatus['paid'] ?? 0),
                'processing_orders' => (int) ($ordersByStatus['processing'] ?? 0),
                'shipped_orders' => (int) ($ordersByStatus['shipped'] ?? 0),
                'revenue' => (int) ($paidRevenue->all_time ?? 0),
                'revenue_range' => (int) ($rangeAgg->revenue ?? 0),
                'orders_range' => (int) ($rangeAgg->orders ?? 0),
                'revenue_today' => (int) ($paidRevenue->today ?? 0),
                'revenue_month' => (int) ($paidRevenue->month ?? 0),
                'customers_count' => User::where('role', 'customer')->count(),
                'unread_messages' => ContactMessage::where('is_read', false)->count(),
                'unread_support' => SupportTicket::where('unread_by_admin', true)->count(),
                'orders_by_status' => $ordersByStatus,
                'sales_chart' => $chart,
                'recent_orders' => Order::with([
                    'user' => fn ($q) => $q->select('id', 'phone', 'name', 'first_name', 'last_name')->withCount('addresses'),
                ])->latest()->limit(8)->get(),
                'low_stock_products' => Product::with('category:id,name_fa')
                    ->where('stock', '<=', $threshold)
                    ->where('is_active', true)
                    ->orderBy('stock')
                    ->limit(8)
                    ->get(['id', 'name_fa', 'stock', 'category_id', 'base_price']),
            ],
        ]);
    }
}
