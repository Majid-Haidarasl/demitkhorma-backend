<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $coupons = Coupon::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($inner) use ($s) {
                    $inner->where('code', 'like', "%{$s}%")
                        ->orWhere('title', 'like', "%{$s}%");
                });
            })
            ->latest()
            ->paginate($request->safePerPage( 20));

        return response()->json($coupons);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['code'] = strtoupper($data['code']);
        $coupon = Coupon::create($data);
        ActivityLogger::log('coupon.created', $coupon, ['code' => $coupon->code]);

        return response()->json(['data' => $coupon], 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $this->validated($request, $coupon->id);
        $data['code'] = strtoupper($data['code']);
        $coupon->update($data);
        ActivityLogger::log('coupon.updated', $coupon, ['code' => $coupon->code]);

        return response()->json(['data' => $coupon->fresh()]);
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        ActivityLogger::log('coupon.deleted', $coupon, ['code' => $coupon->code]);
        $coupon->delete();

        return response()->json(['message' => 'کد تخفیف حذف شد.']);
    }

    public function generate(): JsonResponse
    {
        do {
            $code = 'ZK'.Str::upper(Str::random(8));
        } while (Coupon::where('code', $code)->exists());

        return response()->json(['data' => ['code' => $code]]);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $codeRule = Rule::unique('coupons', 'code');
        if ($ignoreId) {
            $codeRule = $codeRule->ignore($ignoreId);
        }

        return $request->validate([
            'code' => ['required', 'string', 'max:40', $codeRule],
            'title' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => [
                'required',
                'integer',
                'min:1',
                Rule::when($request->input('type') === 'percent', ['max:100']),
            ],
            'min_order' => ['nullable', 'integer', 'min:0'],
            'max_discount' => ['nullable', 'integer', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
