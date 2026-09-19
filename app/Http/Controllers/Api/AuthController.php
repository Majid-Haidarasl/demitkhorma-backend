<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SupportTicket;
use App\Services\OtpService;
use App\Support\SafeInput;
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

        $email = $this->emailForOtp($request, $user);
        if ($email) {
            $otp->rememberEmail($phone, $email);
        }

        $emailed = $otp->send($phone, $email);

        return response()->json($this->otpSentResponse($emailed, next: 'otp'));
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

        $email = $this->emailForOtp($request, $user);
        if ($email) {
            $otp->rememberEmail($phone, $email);
        }

        $emailed = $otp->send($phone, $email);

        return response()->json($this->otpSentResponse($emailed));
    }

    public function register(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'size:6'],
            'password' => $this->passwordRules(true),
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'email' => OtpService::requiresEmail()
                ? ['required', 'email', 'max:255']
                : ['nullable', 'email', 'max:255'],
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
        ];

        $email = $this->uniqueEmail($data['email'] ?? $otp->pullEmail($phone), $user?->id);
        if (OtpService::requiresEmail() && ! $email) {
            throw ValidationException::withMessages([
                'email' => ['فعلاً ثبت‌نام فقط با ایمیل ممکن است. لطفاً ایمیل را وارد کنید.'],
            ]);
        }
        if ($email) {
            $payload['email'] = $email;
        }

        if (! empty($data['birth_date'])) {
            $payload['birth_date'] = $data['birth_date'];
        }

        if (! $user) {
            try {
                $user = User::create([
                    'phone' => $phone,
                    ...$payload,
                ]);
                $user->forceFill([
                    'role' => 'customer',
                    'phone_verified_at' => now(),
                ])->save();
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'email')) {
                    throw ValidationException::withMessages([
                        'email' => ['این ایمیل قبلاً ثبت شده است.'],
                    ]);
                }

                throw ValidationException::withMessages([
                    'phone' => ['این شماره قبلاً ثبت شده است. وارد شوید.'],
                ]);
            }
        } else {
            $user->update($payload + [
                'birth_date' => ! empty($data['birth_date']) ? $data['birth_date'] : $user->birth_date,
            ]);
            $user->forceFill([
                'phone_verified_at' => $user->phone_verified_at ?? now(),
            ])->save();
        }

        $otp->forgetEmail($phone);

        return response()->json([
            'message' => 'ثبت‌نام با موفقیت انجام شد.',
            'data' => $this->authPayload($user->fresh()),
        ]);
    }

    public function sendOtp(Request $request, OtpService $otp): JsonResponse
    {
        $phone = $this->validatePhone($request);
        $user = User::where('phone', $phone)->first();
        $email = $this->emailForOtp($request, $user);
        if ($email) {
            $otp->rememberEmail($phone, $email);
        }
        $emailed = $otp->send($phone, $email);

        return response()->json($this->otpSentResponse($emailed));
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

        $pendingEmail = $this->uniqueEmail($otp->pullEmail($phone), $user->id);
        if ($pendingEmail && ! $user->email) {
            $user->update(['email' => $pendingEmail]);
        }
        $otp->forgetEmail($phone);

        return response()->json([
            'data' => $this->authPayload($user->fresh()),
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

        $email = $this->emailForOtp($request, $user);
        if ($email) {
            $otp->rememberEmail($phone, $email);
        }

        $emailed = $otp->send($phone, $email, 'بازیابی رمز عبور');

        return response()->json($this->otpSentResponse($emailed, recovery: true));
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

        $pendingEmail = $this->uniqueEmail($otp->pullEmail($phone), $user->id);
        if ($pendingEmail && ! $user->email) {
            $user->update(['email' => $pendingEmail]);
        }
        $otp->forgetEmail($phone);

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

    public function updateAdminProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $user->update([
            'email' => $this->uniqueEmail($data['email'] ?? null, $user->id),
        ]);

        return response()->json([
            'message' => 'ایمیل مدیر ذخیره شد.',
            'data' => $user->fresh(),
        ]);
    }

    public function sendAdminPasswordResetOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:80'],
        ]);

        $user = User::query()
            ->where('username', $data['username'])
            ->where('role', 'admin')
            ->first();

        if ($user?->email) {
            $otp->send(OtpService::adminTarget($user->id), $user->email, 'بازیابی رمز مدیر');
        }

        return response()->json([
            'message' => 'در صورت وجود حساب و ایمیل، کد بازیابی ارسال شد.',
            'data' => ['expires_in' => OtpService::TTL_SECONDS, 'emailed' => (bool) $user?->email],
        ]);
    }

    public function confirmAdminOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = User::query()
            ->where('username', $data['username'])
            ->where('role', 'admin')
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => ['کد وارد شده نادرست است.'],
            ]);
        }

        $otp->verify(OtpService::adminTarget($user->id), $data['code']);

        return response()->json(['message' => 'کد تأیید شد.']);
    }

    public function resetAdminPassword(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'size:6'],
            'password' => $this->passwordRules(true),
        ], $this->passwordMessages());

        $user = User::query()
            ->where('username', $data['username'])
            ->where('role', 'admin')
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'username' => ['نام کاربری یا کد نامعتبر است.'],
            ]);
        }

        $otp->consumeProofOrCode(OtpService::adminTarget($user->id), $data['code'] ?? null);
        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();

        return response()->json(['message' => 'رمز عبور مدیر با موفقیت تغییر کرد.']);
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
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $user->update([
            'first_name' => SafeInput::text($data['first_name']),
            'last_name' => SafeInput::text($data['last_name']),
            'name' => trim(SafeInput::text($data['first_name']).' '.SafeInput::text($data['last_name'])),
            'birth_date' => $data['birth_date'] ?? null,
            'email' => $this->uniqueEmail($data['email'] ?? null, $user->id),
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

    private function emailForOtp(Request $request, ?User $user): ?string
    {
        $fromRequest = $this->optionalEmail($request, $user?->id);
        $stored = $this->normalizeStoredEmail($user?->email);
        $email = $fromRequest ?? $stored;

        if (OtpService::requiresEmail() && ! $email) {
            throw ValidationException::withMessages([
                'email' => ['فعلاً کد تأیید فقط به ایمیل ارسال می‌شود. لطفاً ایمیل را وارد کنید.'],
            ]);
        }

        return $email;
    }

    private function normalizeStoredEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function otpSentResponse(bool $emailed, ?string $next = null, bool $recovery = false): array
    {
        $message = match (true) {
            $emailed && OtpService::sendsSms() => $recovery
                ? 'کد بازیابی به موبایل و ایمیل ارسال شد.'
                : 'کد تأیید به موبایل و ایمیل ارسال شد.',
            $emailed => $recovery ? 'کد بازیابی به ایمیل ارسال شد.' : 'کد تأیید به ایمیل ارسال شد.',
            default => $recovery ? 'کد بازیابی به موبایل ارسال شد.' : 'کد تأیید به موبایل ارسال شد.',
        };

        $data = [
            'expires_in' => OtpService::TTL_SECONDS,
            'emailed' => $emailed,
        ];
        if ($next) {
            $data['next'] = $next;
        }

        return [
            'message' => $message,
            'data' => $data,
        ];
    }

    private function optionalEmail(Request $request, ?int $ignoreUserId = null): ?string
    {
        $raw = strtolower(trim((string) $request->input('email', '')));
        if ($raw === '') {
            return null;
        }

        if (! filter_var($raw, FILTER_VALIDATE_EMAIL) || strlen($raw) > 255) {
            throw ValidationException::withMessages([
                'email' => ['ایمیل وارد شده معتبر نیست.'],
            ]);
        }

        return $this->uniqueEmail($raw, $ignoreUserId);
    }

    private function uniqueEmail(?string $email, ?int $ignoreUserId = null): ?string
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => ['ایمیل وارد شده معتبر نیست.'],
            ]);
        }

        $taken = User::query()
            ->where('email', $email)
            ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'email' => ['این ایمیل قبلاً ثبت شده است.'],
            ]);
        }

        return $email;
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
