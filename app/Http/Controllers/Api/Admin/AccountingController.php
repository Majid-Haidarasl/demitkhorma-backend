<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryPurchase;
use App\Models\OperatingExpense;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AccountingController extends Controller
{
    public function __construct(private AccountingService $accounting) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->accounting->summary($from, $to)]);
    }

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => $this->accounting->catalog()]);
    }

    public function purchases(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $rows = InventoryPurchase::query()
            ->with(['product:id,name_fa', 'variant:id,weight_grams,sku'])
            ->whereBetween('purchased_at', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->latest('purchased_at')
            ->latest('id')
            ->paginate($request->safePerPage( 20));

        return response()->json($rows);
    }

    public function storePurchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'qty' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'integer', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'purchased_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $purchase = $this->accounting->recordPurchase($data, $request->user()?->id);

        return response()->json(['data' => $purchase], 201);
    }

    public function updatePurchase(Request $request, InventoryPurchase $purchase): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'integer', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'purchased_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->accounting->updatePurchase($purchase, $data)]);
    }

    public function destroyPurchase(InventoryPurchase $purchase): JsonResponse
    {
        $this->accounting->deletePurchase($purchase);

        return response()->json(['message' => 'خرید کالا حذف شد و از موجودی کم شد.']);
    }

    public function expenses(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $rows = OperatingExpense::query()
            ->whereBetween('spent_at', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->latest('spent_at')
            ->latest('id')
            ->paginate($request->safePerPage( 20));

        return response()->json($rows);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $data = $this->validatedExpense($request);
        $data['created_by'] = $request->user()?->id;
        $expense = OperatingExpense::create($data);

        return response()->json(['data' => $expense], 201);
    }

    public function updateExpense(Request $request, OperatingExpense $expense): JsonResponse
    {
        $expense->update($this->validatedExpense($request));

        return response()->json(['data' => $expense->fresh()]);
    }

    public function destroyExpense(OperatingExpense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(['message' => 'هزینه حذف شد.']);
    }

    public function expenseCategories(): JsonResponse
    {
        return response()->json([
            'data' => collect(OperatingExpense::CATEGORIES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
            ])->values(),
        ]);
    }

    private function validatedExpense(Request $request): array
    {
        return $request->validate([
            'category' => ['required', 'string', Rule::in(array_keys(OperatingExpense::CATEGORIES))],
            'title' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'integer', 'min:0'],
            'spent_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();
        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : now()->subDays(29)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }
}
