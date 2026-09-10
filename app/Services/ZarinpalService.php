<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZarinpalService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.zarinpal.sandbox')
            ? 'https://sandbox.zarinpal.com/pg/v4/payment'
            : 'https://payment.zarinpal.com/pg/v4/payment';
    }

    public function request(int $amount, string $description, string $callbackUrl, array $metadata = []): array
    {
        $merchantId = config('services.zarinpal.merchant_id');

        if (! $merchantId) {
            throw new RuntimeException('درگاه پرداخت هنوز پیکربندی نشده است.');
        }

        $rialAmount = $this->toRial($amount);

        $http = Http::timeout(30)->post("{$this->baseUrl}/request.json", [
            'merchant_id' => $merchantId,
            'amount' => $rialAmount,
            'description' => $description,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);

        if (! $http->successful()) {
            throw new RuntimeException('Zarinpal request HTTP '.$http->status());
        }

        $response = $http->json() ?? [];

        if (($response['data']['code'] ?? 0) !== 100) {
            throw new RuntimeException($response['errors']['message'] ?? 'Zarinpal request failed.');
        }

        $authority = $response['data']['authority'] ?? null;
        if (! $authority) {
            throw new RuntimeException('Zarinpal request missing authority.');
        }

        $gateway = config('services.zarinpal.sandbox')
            ? 'https://sandbox.zarinpal.com/pg/StartPay/'
            : 'https://www.zarinpal.com/pg/StartPay/';

        return [
            'authority' => $authority,
            'payment_url' => $gateway.$authority,
        ];
    }

    public function verify(int $amount, string $authority): string
    {
        $http = Http::timeout(30)->post("{$this->baseUrl}/verify.json", [
            'merchant_id' => config('services.zarinpal.merchant_id'),
            'amount' => $this->toRial($amount),
            'authority' => $authority,
        ]);

        if (! $http->successful()) {
            throw new RuntimeException('Zarinpal verify HTTP '.$http->status());
        }

        $response = $http->json() ?? [];
        $code = $response['data']['code'] ?? 0;

        if (! in_array($code, [100, 101], true)) {
            throw new RuntimeException($response['errors']['message'] ?? 'Payment verification failed.');
        }

        return (string) ($response['data']['ref_id'] ?? '');
    }

    private function toRial(int $amount): int
    {
        return config('services.zarinpal.amount_unit', 'toman') === 'rial'
            ? $amount
            : $amount * 10;
    }
}
