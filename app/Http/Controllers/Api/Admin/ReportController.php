<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function sales(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $paid = ['paid', 'processing', 'shipped', 'delivered'];

        $summary = [
            'orders' => Order::whereIn('status', $paid)->whereBetween('created_at', [$from, $to])->count(),
            'revenue' => (int) Order::whereIn('status', $paid)->whereBetween('created_at', [$from, $to])->sum('total'),
            'discount' => (int) Order::whereIn('status', $paid)->whereBetween('created_at', [$from, $to])->sum('discount_amount'),
            'avg_order' => 0,
        ];
        $summary['avg_order'] = $summary['orders'] > 0 ? (int) round($summary['revenue'] / $summary['orders']) : 0;

        $topProducts = OrderItem::query()
            ->select(
                'product_id',
                'product_name',
                DB::raw('SUM(qty) as qty_sold'),
                DB::raw('SUM(qty * price_snapshot) as revenue')
            )
            ->whereHas('order', fn ($q) => $q->whereIn('status', $paid)->whereBetween('created_at', [$from, $to]))
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $byCategory = OrderItem::query()
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status', $paid)
            ->whereBetween('orders.created_at', [$from, $to])
            ->select(
                'categories.id',
                'categories.name_fa',
                DB::raw('SUM(order_items.qty) as qty_sold'),
                DB::raw('SUM(order_items.qty * order_items.price_snapshot) as revenue')
            )
            ->groupBy('categories.id', 'categories.name_fa')
            ->orderByDesc('revenue')
            ->get();

        $daily = Order::whereIn('status', $paid)
            ->whereBetween('created_at', [$from, $to])
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as orders'), DB::raw('SUM(total) as revenue'))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'summary' => $summary,
                'top_products' => $topProducts,
                'by_category' => $byCategory,
                'daily' => $daily,
                'low_stock' => Product::with('category:id,name_fa')
                    ->where('is_active', true)
                    ->where('stock', '<=', (int) ($request->input('stock_threshold', SiteSetting::get('low_stock_threshold', 5) ?: 5)))
                    ->orderBy('stock')
                    ->limit(20)
                    ->get(['id', 'name_fa', 'stock', 'category_id']),
            ],
        ]);
    }

    private function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();
        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : now()->subDays(29)->startOfDay();

        return [$from, $to];
    }
}
