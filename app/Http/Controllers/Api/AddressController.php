<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()->addresses()->latest()->get();

        return response()->json(['data' => $addresses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $isFirst = ! $request->user()->addresses()->exists();
        if ($isFirst || ($data['is_default'] ?? false)) {
            $request->user()->addresses()->update(['is_default' => false]);
            $data['is_default'] = true;
        }

        $address = $request->user()->addresses()->create($data);

        return response()->json(['data' => $address], 201);
    }

    public function update(Request $request, Address $address): JsonResponse
    {
        $this->authorizeAddress($request, $address);

        $data = $this->validated($request);

        if ($data['is_default'] ?? false) {
            $request->user()->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
        }

        $address->update($data);

        return response()->json(['data' => $address->fresh()]);
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        $this->authorizeAddress($request, $address);
        $address->delete();

        return response()->json(['message' => 'آدرس حذف شد.']);
    }

    private function validated(Request $request): array
    {
        $request->merge([
            'phone' => \App\Services\OtpService::normalizePhone((string) $request->input('phone', '')),
            'postal_code' => Address::normalizePostal($request->input('postal_code')),
        ]);

        return $request->validate([
            'recipient_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'province' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'postal_code' => ['nullable', 'regex:/^\d{10}$/'],
            'is_default' => ['sometimes', 'boolean'],
        ], [
            'phone.regex' => 'شماره موبایل را با صفر اول وارد کنید (مثال: ۰۹۱۲۳۴۵۶۷۸۹).',
            'postal_code.regex' => 'کد پستی باید ۱۰ رقم باشد.',
        ]);
    }

    private function authorizeAddress(Request $request, Address $address): void
    {
        abort_if($address->user_id !== $request->user()->id, 403);
    }
}
