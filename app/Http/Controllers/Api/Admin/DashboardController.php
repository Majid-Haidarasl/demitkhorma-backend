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

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'low_stock_threshold' => $threshold,
                'products_count' => Product::count(),
                'active_products' => Product::where('is_active', true)->count(),
                'low_stock_count' => Product::where('stock', '<=', $threshold)->where('is_active', true)->count(),
                'orders_count' => Order::count(),
                'pending_orders' => Order::where('status', 'pending')->count(),
                'attention_orders' => Order::whereIn('status', Order::ATTENTION_STATUSES)->count(),
                'paid_orders' => Order::where('status', 'paid')->count(),
                'processing_orders' => Order::where('status', 'processing')->count(),
                'shipped_orders' => Order::where('status', 'shipped')->count(),
                'revenue' => (int) Order::whereIn('status', $paidStatuses)->sum('total'),
                'revenue_range' => (int) (clone $rangeOrders)->sum('total'),
                'orders_range' => (clone $rangeOrders)->count(),
                'revenue_today' => (int) Order::whereIn('status', $paidStatuses)->whereDate('created_at', today())->sum('total'),
                'revenue_month' => (int) Order::whereIn('status', $paidStatuses)
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->sum('total'),
                'customers_count' => User::where('role', 'customer')->count(),
                'unread_messages' => ContactMessage::where('is_read', false)->count(),
                'unread_support' => SupportTicket::where('unread_by_admin', true)->count(),
                'orders_by_status' => Order::select('status', DB::raw('count(*) as total'))
                    ->groupBy('status')
                    ->pluck('total', 'status'),
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
