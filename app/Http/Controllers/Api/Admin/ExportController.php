<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\SafeInput;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function orders(Request $request): StreamedResponse
    {
        $query = Order::with(['user' => fn ($q) => $q->withCount('addresses')])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest();

        ActivityLogger::log('export.orders');

        return $this->csv('orders-export.csv', [
            'id', 'phone', 'name', 'subtotal', 'discount', 'shipping', 'total', 'status', 'coupon', 'created_at',
        ], function () use ($query) {
            foreach ($query->cursor() as $o) {
                yield [
                    SafeInput::csvCell($o->id),
                    SafeInput::csvCell($o->user?->phone),
                    SafeInput::csvCell($this->customerLabel($o->user)),
                    SafeInput::csvCell($o->subtotal),
                    SafeInput::csvCell($o->discount_amount),
                    SafeInput::csvCell($o->shipping_cost),
                    SafeInput::csvCell($o->total),
                    SafeInput::csvCell($o->status),
                    SafeInput::csvCell($o->coupon_code),
                    SafeInput::csvCell($o->created_at?->toDateTimeString()),
                ];
            }
        });
    }

    public function customers(): StreamedResponse
    {
        ActivityLogger::log('export.customers');

        $query = User::where('role', 'customer')->withCount(['orders', 'addresses'])->latest();

        return $this->csv('customers-export.csv', [
            'id', 'phone', 'name', 'orders_count', 'verified_at', 'created_at',
        ], function () use ($query) {
            foreach ($query->cursor() as $u) {
                yield [
                    SafeInput::csvCell($u->id),
                    SafeInput::csvCell($u->phone),
                    SafeInput::csvCell($this->customerLabel($u)),
                    SafeInput::csvCell($u->orders_count),
                    SafeInput::csvCell($u->phone_verified_at?->toDateTimeString()),
                    SafeInput::csvCell($u->created_at?->toDateTimeString()),
                ];
            }
        });
    }

    private function customerLabel(?User $user): string
    {
        if (! $user) {
            return '';
        }

        if (! $user->hasCompleteProfile()) {
            return 'پروفایل ناقص';
        }

        $full = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $full !== '' ? $full : (string) ($user->name ?: 'پروفایل ناقص');
    }

    private function csv(string $filename, array $headers, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $headers);
            foreach ($rows() as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
