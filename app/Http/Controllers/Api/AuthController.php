<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SupportTicket;
use App\Services\OtpService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function start(Request $request, OtpService $otp): JsonResponse
    {
        $phone = $this->validatePhone($request);
        $user = User::where('phone', $phone)->first();

        if ($user && $user->password) {
            return response()->json([
                'data' => ['next' => 'password'],
            ]);
        }

        $otp->send($phone);

        return response()->json([
            'message' => 'کد تأیید ارسال شد.',
            'data' => ['next' => 'otp', 'expires_in' => OtpService::TTL_SECONDS],
        ]);
    }

    public function registerSendOtp(Request $request, OtpService $otp): JsonResponse
    {
        $phone = $this->validatePhone($request);

        $user = User::where('phone', $phone)->where('role', 'customer')->first();

        if ($user?->password) {
            throw ValidationException::withMessages([
                'phone' => ['این شماره قبلاً ثبت شده است. وارد شوید.'],
            ]);
        }

        $otp->send($phone);

        return response()->json([
            'message' => 'کد تأیید ارسال شد.',
            'data' => ['expires_in' => OtpService::TTL_SECONDS],
        ]);
    }

    public function register(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'size:6'],
            'password' => $this->passwordRules(true),
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
        ], $this->passwordMessages());

        $phone = $this->validatePhone($request);
        $otp->consumeProofOrCode($phone, $data['code'] ?? null);

        $user = User::where('phone', $phone)->where('role', 'customer')->first();

        if ($user?->password) {
            throw ValidationException::withMessages([
                'phone' => ['این شماره قبلاً ثبت شده است. وارد شوید.'],
            ]);
        }

        $payload = [
            'password' => $data['password'],
            'phone_verified_at' => now(),
        ];

        if (! empty($data['birth_date'])) {
            $payload['birth_date'] = $data['birth_date'];
        }

        if (! $user) {
            try {
                $user = User::create([
                    'phone' => $phone,
                    ...$payload,
                ]);
                $user->forceFill(['role' => 'customer'])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'phone' => ['این شماره قبلاً ثبت شده است. وارد شوید.'],
                ]);
            }
        } else {
            $user->update([
                'password' => $data['password'],
                'phone_verified_at' => $user->phone_verified_at ?? now(),
                'birth_date' => ! empty($data['birth_date']) ? $data['birth_date'] : $user->birth_date,
            ]);
        }

        return response()->json([
            'message' => 'ثبت‌نام با موفقیت انجام شد.',
            'data' => $this->authPayload($user->fresh()),
        ]);
    }

    public function sendOtp(Request $request, OtpService $otp): JsonResponse
    {
        $phone = $this->validatePhone($request);

        $otp->send($phone);

        return response()->json([
            'message' => 'کد تأیید ارسال شد.',
            'data' => ['expires_in' => OtpService::TTL_SECONDS],
        ]);
    }

    public function confirmOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $phone = $this->validatePhone($request);
        $otp->verify($phone, $data['code']);

        return response()->json(['message' => 'کد تأیید شد.']);
    }

    public function verifyOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $phone = $this->validatePhone($request);
        $otp->verify($phone, $data['code']);

        $user = User::firstOrCreate(
            ['phone' => $phone],
        );

        if (! $user->role) {
            $user->forceFill(['role' => 'customer'])->save();
        }

        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'phone' => ['شماره موبایل یا رمز عبور اشتباه است.'],
            ]);
        }

        if (! $user->phone_verified_at) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        return response()->json([
            'data' => $this->authPayload($user),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $phone = $this->validatePhone($request);

        $user = User::where('phone', $phone)->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['شماره موبایل یا رمز عبور اشتباه است.'],
            ]);
        }

        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'phone' => ['شماره موبایل یا رمز عبور اشتباه است.'],
            ]);
        }

        return response()->json([
            'data' => $this->authPayload($user),
        ]);
    }

    public function sendPasswordResetOtp(Request $request, OtpService $otp): JsonResponse
    {
        $phone = $this->validatePhone($request);

        $user = User::where('phone', $phone)->where('role', 'customer')->first();

        if (! $user) {
            return response()->json(['message' => 'در صورت وجود حساب، کد تأیید ارسال شد.']);
        }

        $otp->send($phone);

        return response()->json([
            'message' => 'کد بازیابی ارسال شد.',
            'data' => ['expires_in' => OtpService::TTL_SECONDS],
        ]);
    }

    public function resetPassword(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'size:6'],
            'password' => $this->passwordRules(true),
        ], $this->passwordMessages());

        $phone = $this->validatePhone($request);
        $otp->consumeProofOrCode($phone, $data['code'] ?? null);

        $user = User::where('phone', $phone)->where('role', 'customer')->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'phone' => ['حساب کاربری با این شماره یافت نشد.'],
            ]);
        }

        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();

        if (! $user->phone_verified_at) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        return response()->json([
            'message' => 'رمز عبور با موفقیت تغییر کرد.',
            'data' => $this->authPayload($user->fresh()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            abort(403, 'رمز مدیر را از پنل مدیریت تغییر دهید.');
        }

        $rules = [
            'password' => $this->passwordRules(true),
        ];

        if ($user->password) {
            $rules['current_password'] = ['required', 'string'];
        }

        $data = $request->validate($rules, $this->passwordMessages());

        if ($user->password && ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['رمز عبور فعلی اشتباه است.'],
            ]);
        }

        $user->update(['password' => $data['password']]);

        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
            ->delete();

        return response()->json([
            'message' => 'رمز عبور بروزرسانی شد.',
            'data' => $user->fresh(),
        ]);
    }

    public function adminLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $data['username'])->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['نام کاربری یا رمز عبور اشتباه است.'],
            ]);
        }

        if (! $user->isAdmin()) {
            abort(403, 'Admin access required.');
        }

        return response()->json([
            'data' => $this->authPayload($user),
        ]);
    }

    public function updateAdminPassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => $this->passwordRules(true),
        ], $this->passwordMessages());

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['رمز عبور فعلی اشتباه است.'],
            ]);
        }

        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();

        return response()->json(['message' => 'رمز عبور مدیر بروزرسانی شد.']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            abort(403, 'حساب مدیر را از پنل مدیریت ویرایش کنید. برای خرید با شماره مشتری وارد شوید.');
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
        ]);

        $user->update([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'birth_date' => $data['birth_date'] ?? null,
        ]);

        return response()->json([
            'message' => 'اطلاعات پروفایل ذخیره شد.',
            'data' => $user->fresh()->loadCount('addresses'),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadCount('addresses');

        if ($user->role === 'customer') {
            $user->setAttribute(
                'unread_support_count',
                SupportTicket::query()
                    ->where('user_id', $user->id)
                    ->where('unread_by_customer', true)
                    ->count()
            );
        }

        return response()->json(['data' => $user]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'خروج انجام شد.']);
    }

    private function passwordRules(bool $confirmed = false): array
    {
        $rules = ['required', 'string', 'min:8', 'max:20'];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }

    private function passwordMessages(): array
    {
        return [
            'password.min' => 'رمز عبور باید بین ۸ تا ۲۰ کاراکتر باشد.',
            'password.max' => 'رمز عبور باید بین ۸ تا ۲۰ کاراکتر باشد.',
            'password.confirmed' => 'رمز عبور و تکرار آن یکسان نیست.',
        ];
    }

    private function validatePhone(Request $request): string
    {
        $phone = OtpService::normalizePhone($request->input('phone', ''));

        if (! preg_match('/^09\d{9}$/', $phone)) {
            throw ValidationException::withMessages([
                'phone' => ['شماره موبایل را با صفر اول وارد کنید (مثال: ۰۹۱۲۳۴۵۶۷۸۹).'],
            ]);
        }

        return $phone;
    }

    private function authPayload(User $user): array
    {
        $user->tokens()->where('name', 'api')->delete();
        $token = $user->createToken('api')->plainTextToken;
        $user->loadCount('addresses');

        return [
            'user' => $user,
            'token' => $token,
        ];
    }
}
