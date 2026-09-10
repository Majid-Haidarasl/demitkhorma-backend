<?php

namespace App\Services;

use App\Models\OtpCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public const TTL_SECONDS = 60;

    public const PROOF_TTL_SECONDS = 600;

    private const CODE_LENGTH = 6;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private SmsService $sms) {}

    public function send(string $phone): void
    {
        $existing = OtpCode::query()->where('phone', $phone)->latest()->first();

        if ($existing && $existing->expires_at->isFuture()) {
            $wait = max(1, $existing->expires_at->getTimestamp() - now()->getTimestamp());

            throw ValidationException::withMessages([
                'phone' => ["کد قبلی هنوز معتبر است. لطفاً {$wait} ثانیه بعد کد جدید بگیرید."],
            ]);
        }

        OtpCode::where('phone', $phone)->delete();
        Cache::forget($this->attemptsKey($phone));
        Cache::forget($this->verifiedKey($phone));

        $max = (10 ** self::CODE_LENGTH) - 1;
        $code = str_pad((string) random_int(0, $max), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        if (! app()->isProduction()) {
            $line = "[OTP] {$phone} => {$code}";
            Log::info($line);
            error_log($line);
            file_put_contents('php://stderr', $line.PHP_EOL, FILE_APPEND);
        }

        try {
            $this->sms->sendOtp($phone, $code);
        } catch (\Throwable $e) {
            Log::error('OTP SMS send failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            // Local/dev: keep OTP and log the code so flows can be tested without SMS provider.
            if (app()->isLocal()) {
                Log::warning("LOCAL OTP for {$phone}: {$code}");

                return;
            }

            OtpCode::where('phone', $phone)->delete();

            throw ValidationException::withMessages([
                'phone' => ['ارسال پیامک موقتاً ممکن نیست. لطفاً چند لحظه بعد دوباره تلاش کنید.'],
            ]);
        }
    }

    public function verify(string $phone, string $code): void
    {
        $attempts = (int) Cache::get($this->attemptsKey($phone), 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            OtpCode::where('phone', $phone)->delete();

            throw ValidationException::withMessages([
                'code' => ['تعداد تلاش بیش از حد مجاز است. لطفاً کد جدید درخواست کنید.'],
            ]);
        }

        $otp = OtpCode::where('phone', $phone)->latest()->first();

        if (! $otp || $otp->expires_at->lte(now())) {
            OtpCode::where('phone', $phone)->delete();

            throw ValidationException::withMessages([
                'code' => ['کد منقضی شده است. لطفاً کد جدید درخواست کنید.'],
            ]);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $attempts++;
            Cache::put($this->attemptsKey($phone), $attempts, now()->addMinutes(15));

            if ($attempts >= self::MAX_ATTEMPTS) {
                OtpCode::where('phone', $phone)->delete();

                throw ValidationException::withMessages([
                    'code' => ['تعداد تلاش بیش از حد مجاز است. لطفاً کد جدید درخواست کنید.'],
                ]);
            }

            throw ValidationException::withMessages([
                'code' => ['کد وارد شده نادرست است.'],
            ]);
        }

        Cache::forget($this->attemptsKey($phone));
        OtpCode::where('phone', $phone)->delete();
        Cache::put($this->verifiedKey($phone), 1, now()->addSeconds(self::PROOF_TTL_SECONDS));
    }

    public function consumeProofOrCode(string $phone, ?string $code): void
    {
        if (Cache::pull($this->verifiedKey($phone))) {
            return;
        }

        if (is_string($code) && $code !== '') {
            $this->verify($phone, $code);
            Cache::forget($this->verifiedKey($phone));

            return;
        }

        throw ValidationException::withMessages([
            'code' => ['کد منقضی شده است. لطفاً کد جدید درخواست کنید.'],
        ]);
    }

    public static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '98') && strlen($phone) === 12) {
            $phone = '0'.substr($phone, 2);
        }

        return $phone;
    }

    private function attemptsKey(string $phone): string
    {
        return 'otp_attempts:'.$phone;
    }

    private function verifiedKey(string $phone): string
    {
        return 'otp_verified:'.$phone;
    }
}
