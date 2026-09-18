<?php

namespace App\Services;

use App\Models\CustomerMessageCampaign;
use App\Models\CustomerMessageLog;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CustomerMessageService
{
    public const MAX_RECIPIENTS = 500;

    public function __construct(
        private SmsService $sms,
        private ShopMailService $mail,
    ) {}

    public function recipients(string $audience, array $userIds = [], string $channel = 'sms'): Collection
    {
        $query = User::query()
            ->where('role', 'customer')
            ->withCount(['orders', 'addresses']);

        if ($channel === 'email') {
            $query->whereNotNull('email')->where('email', '!=', '');
        } else {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        }

        $users = match ($audience) {
            'all' => $query->orderByDesc('id')->get(),
            'birthday_today' => $this->filterJalaliBirthday($query->whereNotNull('birth_date')->get(), 'today'),
            'birthday_month' => $this->filterJalaliBirthday($query->whereNotNull('birth_date')->get(), 'month'),
            'profile_incomplete' => $query->orderByDesc('id')->get()->filter(fn (User $u) => ! $u->hasCompleteProfile())->values(),
            'profile_complete' => $query->orderByDesc('id')->get()->filter(fn (User $u) => $u->hasCompleteProfile())->values(),
            'no_orders' => $query->doesntHave('orders')->orderByDesc('id')->get(),
            'has_orders' => $query->has('orders')->orderByDesc('id')->get(),
            'selected' => empty($userIds)
                ? collect()
                : $query->whereIn('id', $userIds)->orderByDesc('id')->get(),
            default => throw ValidationException::withMessages([
                'audience' => ['فیلتر مخاطب نامعتبر است.'],
            ]),
        };

        $users = $users->unique('id')->values();

        if ($channel === 'email') {
            return $users->filter(fn (User $u) => filter_var(strtolower(trim((string) $u->email)), FILTER_VALIDATE_EMAIL))->values();
        }

        return $users;
    }

    public function send(
        string $audience,
        string $message,
        array $userIds,
        ?int $adminId,
        string $channel = 'sms',
        ?string $subject = null,
    ): CustomerMessageCampaign {
        $channel = $channel === 'email' ? 'email' : 'sms';
        $subject = $channel === 'email'
            ? (trim((string) $subject) ?: 'پیام از دمیت خرما')
            : null;

        $recipients = $this->recipients($audience, $userIds, $channel);

        if ($recipients->isEmpty()) {
            $need = $channel === 'email' ? 'ایمیل' : 'شماره موبایل';
            throw ValidationException::withMessages([
                ($audience === 'selected' ? 'user_ids' : 'audience') => [
                    $audience === 'selected'
                        ? "حداقل یک مشتری با {$need} انتخاب کنید."
                        : "با این فیلتر مخاطبی با {$need} یافت نشد.",
                ],
            ]);
        }

        if ($recipients->count() > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessages([
                'audience' => ['حداکثر '.self::MAX_RECIPIENTS.' مخاطب در هر ارسال مجاز است. فیلتر را محدودتر کنید.'],
            ]);
        }

        $campaign = CustomerMessageCampaign::create([
            'audience' => $audience,
            'channel' => $channel,
            'subject' => $subject,
            'message' => $message,
            'recipients_count' => $recipients->count(),
            'sent_count' => 0,
            'failed_count' => 0,
            'created_by' => $adminId,
        ]);

        $sent = 0;
        $failed = 0;

        foreach ($recipients as $user) {
            $phone = $user->phone ? OtpService::normalizePhone((string) $user->phone) : '';
            $email = strtolower(trim((string) $user->email));
            $body = $this->personalize($message, $user);

            try {
                if ($channel === 'email') {
                    $this->mail->sendCustomerMessage($email, $subject, $body);
                } else {
                    $this->sms->sendText($phone, $body);
                }

                CustomerMessageLog::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'email' => $email ?: null,
                    'status' => 'sent',
                ]);
                $sent++;
            } catch (\Throwable $e) {
                CustomerMessageLog::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'email' => $email ?: null,
                    'status' => 'failed',
                    'error' => mb_substr($e->getMessage(), 0, 240),
                ]);
                $failed++;
            }
        }

        $campaign->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
        ]);

        ActivityLogger::log('customer.message.sent', $campaign, [
            'audience' => $audience,
            'channel' => $channel,
            'sent' => $sent,
            'failed' => $failed,
        ]);

        return $campaign->fresh();
    }

    public function personalize(string $message, User $user): string
    {
        $first = trim((string) $user->first_name) ?: 'مشتری عزیز';
        $full = trim(trim((string) $user->first_name).' '.trim((string) $user->last_name)) ?: $first;

        return strtr($message, [
            '{first_name}' => $first,
            '{name}' => $full,
            '{phone}' => (string) $user->phone,
            '{email}' => (string) $user->email,
        ]);
    }

    private function filterJalaliBirthday(Collection $users, string $mode): Collection
    {
        [, $jm, $jd] = Jalali::ymd(now('Asia/Tehran'));

        return $users->filter(function (User $user) use ($mode, $jm, $jd) {
            [, $m, $d] = Jalali::ymd($user->birth_date);
            if ($m < 1) {
                return false;
            }

            return $mode === 'month' ? $m === $jm : ($m === $jm && $d === $jd);
        })->values();
    }
}
