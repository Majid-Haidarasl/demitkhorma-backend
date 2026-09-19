<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
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

    public function __construct(
        private SmsService $sms,
        private ShopMailService $mail,
    ) {}

    public function rememberEmail(string $phone, ?string $email): void
    {
        $email = strtolower(trim((string) $email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        Cache::put($this->emailKey($phone), $email, now()->addMinutes(15));
    }

    public function pullEmail(string $phone): ?string
    {
        $email = Cache::get($this->emailKey($phone));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function forgetEmail(string $phone): void
    {
        Cache::forget($this->emailKey($phone));
    }

    public static function channel(): string
    {
        $channel = strtolower((string) config('services.otp.channel', 'email'));

        return in_array($channel, ['email', 'sms', 'both'], true) ? $channel : 'email';
    }

    public static function sendsSms(): bool
    {
        return self::channel() !== 'email';
    }

    public static function sendsEmail(): bool
    {
        return self::channel() !== 'sms';
    }

    public static function requiresEmail(): bool
    {
        return self::channel() === 'email';
    }

    public function send(string $phone, ?string $email = null, string $purpose = 'ورود / ثبت‌نام'): bool
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

        if (app()->isLocal()) {
            $line = "[OTP] {$phone} => {$code}";
            Log::info($line);
            error_log($line);
            file_put_contents('php://stderr', $line.PHP_EOL, FILE_APPEND);
        }

        $isMobile = (bool) preg_match('/^09\d{9}$/', $phone);
        $isAdminTarget = str_starts_with($phone, 'admin:');
        $email = $this->normalizeEmail($email) ?? $this->resolveEmail($phone);
        $emailed = false;
        $smsSent = false;
        $wantEmail = self::sendsEmail() || $isAdminTarget;
        $wantSms = self::sendsSms() && $isMobile;

        if ($wantEmail) {
            if (! $email) {
                if ($isAdminTarget || self::requiresEmail()) {
                    OtpCode::where('phone', $phone)->delete();

                    throw ValidationException::withMessages([
                        'email' => $isAdminTarget
                            ? ['برای بازیابی رمز مدیر، ایمیل حساب لازم است.']
                            : ['فعلاً کد تأیید فقط به ایمیل ارسال می‌شود. لطفاً ایمیل را وارد کنید.'],
                    ]);
                }
            } else {
                try {
                    $this->mail->sendOtp($email, $code, $purpose);
                    $emailed = true;
                } catch (\Throwable $e) {
                    Log::error('OTP email send failed', [
                        'email' => $email,
                        'error' => $e->getMessage(),
                    ]);

                    if (! $wantSms) {
                        OtpCode::where('phone', $phone)->delete();

                        throw ValidationException::withMessages([
                            'email' => ['ارسال ایمیل موقتاً ممکن نیست. لطفاً چند لحظه بعد دوباره تلاش کنید.'],
                        ]);
                    }
                }
            }
        }

        if ($wantSms) {
            try {
                $this->sms->sendOtp($phone, $code);
                $smsSent = true;
            } catch (\Throwable $e) {
                Log::error('OTP SMS send failed', [
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);

                if (app()->isLocal()) {
                    Log::warning("LOCAL OTP for {$phone}: {$code}");
                    $smsSent = true;
                } elseif (! $emailed) {
                    OtpCode::where('phone', $phone)->delete();

                    throw ValidationException::withMessages([
                        'phone' => ['ارسال پیامک موقتاً ممکن نیست. لطفاً چند لحظه بعد دوباره تلاش کنید.'],
                    ]);
                }
            }
        }

        if (! $emailed && ! $smsSent) {
            OtpCode::where('phone', $phone)->delete();

            throw ValidationException::withMessages([
                'phone' => ['ارسال کد ممکن نیست.'],
            ]);
        }

        return $emailed;
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

    public static function adminTarget(int $adminId): string
    {
        return 'admin:'.$adminId;
    }

    private function resolveEmail(string $phone): ?string
    {
        $pending = $this->pullEmail($phone);
        if ($pending) {
            return $pending;
        }

        $stored = User::query()
            ->where('phone', $phone)
            ->where('role', 'customer')
            ->value('email');

        return $this->normalizeEmail(is_string($stored) ? $stored : null);
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function emailKey(string $phone): string
    {
        return 'otp_email:'.$phone;
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
