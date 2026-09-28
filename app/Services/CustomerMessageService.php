<?php

namespace App\Services;

use App\Models\CustomerMessageCampaign;
use App\Models\CustomerMessageLog;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CustomerMessageService
{
    public const MAX_RECIPIENTS = 500;

    public function __construct(
        private SmsService $sms,
        private ShopMailService $mail,
    ) {}

    /**
     * @return array{count:int, users:Collection<int, User>}
     */
    public function recipientPreview(string $audience, array $userIds = [], string $channel = 'sms', int $previewLimit = 40): array
    {
        $base = $this->baseQuery($channel);

        if (in_array($audience, ['birthday_today', 'birthday_month'], true)) {
            $users = $this->finalizeRecipients($this->birthdayRecipients($base, $audience), $channel);

            return [
                'count' => $users->count(),
                'users' => $users->take($previewLimit)->values(),
            ];
        }

        $query = $this->applyAudience($base, $audience, $userIds);
        $count = (clone $query)->count();
        $users = $this->finalizeRecipients(
            (clone $query)->orderByDesc('id')->limit($previewLimit)->get(),
            $channel,
        );

        return [
            'count' => $count,
            'users' => $users->values(),
        ];
    }

    public function recipients(string $audience, array $userIds = [], string $channel = 'sms'): Collection
    {
        $base = $this->baseQuery($channel);

        if (in_array($audience, ['birthday_today', 'birthday_month'], true)) {
            return $this->finalizeRecipients($this->birthdayRecipients($base, $audience), $channel);
        }

        // Cap at MAX+1 so send() can reject oversized audiences without loading the whole table.
        $users = $this->applyAudience($base, $audience, $userIds)
            ->orderByDesc('id')
            ->limit(self::MAX_RECIPIENTS + 1)
            ->get();

        return $this->finalizeRecipients($users, $channel);
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
        $logs = [];

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

                $logs[] = [
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'email' => $email ?: null,
                    'status' => 'sent',
                    'error' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $sent++;
            } catch (\Throwable $e) {
                $logs[] = [
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'email' => $email ?: null,
                    'status' => 'failed',
                    'error' => mb_substr($e->getMessage(), 0, 240),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $failed++;
            }
        }

        foreach (array_chunk($logs, 100) as $chunk) {
            CustomerMessageLog::insert($chunk);
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

    private function baseQuery(string $channel): Builder
    {
        $query = User::query()
            ->where('role', 'customer')
            ->withCount(['orders', 'addresses']);

        if ($channel === 'email') {
            $query->whereNotNull('email')->where('email', '!=', '');
        } else {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        }

        return $query;
    }

    private function applyAudience(Builder $query, string $audience, array $userIds): Builder
    {
        return match ($audience) {
            'all' => $query,
            'profile_incomplete' => $query->where(function (Builder $q) {
                $q->whereNull('first_name')->orWhere('first_name', '')
                    ->orWhereNull('last_name')->orWhere('last_name', '')
                    ->orWhereDoesntHave('addresses');
            }),
            'profile_complete' => $query
                ->whereNotNull('first_name')->where('first_name', '!=', '')
                ->whereNotNull('last_name')->where('last_name', '!=', '')
                ->whereHas('addresses'),
            'no_orders' => $query->doesntHave('orders'),
            'has_orders' => $query->has('orders'),
            'selected' => empty($userIds)
                ? $query->whereRaw('0 = 1')
                : $query->whereIn('id', $userIds),
            default => throw ValidationException::withMessages([
                'audience' => ['فیلتر مخاطب نامعتبر است.'],
            ]),
        };
    }

    private function birthdayRecipients(Builder $query, string $audience): Collection
    {
        $mode = $audience === 'birthday_month' ? 'month' : 'today';

        return $this->filterJalaliBirthday(
            $query->whereNotNull('birth_date')->orderByDesc('id')->get(),
            $mode,
        );
    }

    private function finalizeRecipients(Collection $users, string $channel): Collection
    {
        $users = $users->unique('id')->values();

        if ($channel === 'email') {
            return $users->filter(
                fn (User $u) => filter_var(strtolower(trim((string) $u->email)), FILTER_VALIDATE_EMAIL)
            )->values();
        }

        return $users;
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
