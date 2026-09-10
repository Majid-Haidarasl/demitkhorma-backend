<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SmsService
{
    public function sendOtp(string $phone, string $code): void
    {
        $message = "کد ورود دمیت خرما: {$code}";

        match (config('services.sms.driver')) {
            'kavenegar' => $this->sendViaKavenegar($phone, $message),
            'smsir' => $this->sendViaSmsIr($phone, $code, $message),
            default => $this->logToConsole("SMS OTP [{$phone}]: {$code}"),
        };
    }

    public function sendText(string $phone, string $message): void
    {
        match (config('services.sms.driver')) {
            'kavenegar' => $this->sendViaKavenegar($phone, $message),
            'smsir' => $this->sendViaSmsIrBulk($phone, $message, (string) config('services.sms.smsir.api_key')),
            default => $this->logToConsole("SMS TEXT [{$phone}]: {$message}"),
        };
    }

    private function sendViaKavenegar(string $phone, string $message): void
    {
        $apiKey = config('services.sms.kavenegar.api_key');
        $sender = config('services.sms.kavenegar.sender');

        Http::get("https://api.kavenegar.com/v1/{$apiKey}/sms/send.json", [
            'receptor' => $phone,
            'sender' => $sender,
            'message' => $message,
        ])->throw();
    }

    private function sendViaSmsIr(string $phone, string $code, string $message): void
    {
        $apiKey = config('services.sms.smsir.api_key');

        if (! $apiKey) {
            throw new RuntimeException('SMSIR_API_KEY is not configured.');
        }

        $templateId = config('services.sms.smsir.template_id');

        if ($templateId) {
            $this->sendSmsIrVerify($phone, $code, $apiKey, (int) $templateId);

            return;
        }

        $this->sendSmsIrBulk($phone, $message, $apiKey);
    }

    private function sendSmsIrVerify(string $phone, string $code, string $apiKey, int $templateId): void
    {
        $response = $this->smsIrHttp($apiKey)
            ->post('https://api.sms.ir/v1/send/verify', [
                'mobile' => $this->normalizePhoneForSmsIrVerify($phone),
                'templateId' => $templateId,
                'parameters' => [
                    [
                        'name' => config('services.sms.smsir.template_param', 'Code'),
                        'value' => $code,
                    ],
                ],
            ]);

        $this->assertSmsIrSuccess($response, 'verify');
    }

    private function sendSmsIrBulk(string $phone, string $message, string $apiKey): void
    {
        $response = $this->smsIrHttp($apiKey)
            ->post('https://api.sms.ir/v1/send/bulk', [
                'lineNumber' => $this->resolveSmsIrLineNumber($apiKey),
                'messageText' => $message,
                'mobiles' => [$this->normalizePhoneForSmsIrBulk($phone)],
            ]);

        $this->assertSmsIrSuccess($response, 'bulk');
    }

    private function resolveSmsIrLineNumber(string $apiKey): int
    {
        if ($line = config('services.sms.smsir.line_number')) {
            return (int) $line;
        }

        $response = $this->smsIrHttp($apiKey)
            ->get('https://api.sms.ir/v1/line');

        $data = $response->json();

        if (! $response->successful() || ($data['status'] ?? 0) != 1 || empty($data['data'])) {
            throw new RuntimeException(
                'Could not fetch SMS.ir line numbers. Set SMSIR_LINE_NUMBER in .env or create an OTP template and set SMSIR_TEMPLATE_ID.'
            );
        }

        return (int) $data['data'][0];
    }

    private function assertSmsIrSuccess(Response $response, string $method): void
    {
        $data = $response->json();

        if ($response->successful() && ($data['status'] ?? 0) == 1) {
            return;
        }

        Log::error("SMS.ir {$method} failed", [
            'http_status' => $response->status(),
            'response' => $data,
        ]);

        throw new RuntimeException($data['message'] ?? "SMS.ir {$method} request failed.");
    }

    private function smsIrHttp(string $apiKey): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders($this->smsIrHeaders($apiKey))
            ->timeout((int) config('services.sms.smsir.timeout', 30));
    }

    private function smsIrHeaders(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    private function normalizePhoneForSmsIrVerify(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '98')) {
            $phone = substr($phone, 2);
        }

        if (str_starts_with($phone, '0')) {
            $phone = substr($phone, 1);
        }

        return $phone;
    }

    private function normalizePhoneForSmsIrBulk(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '98') && strlen($phone) === 12) {
            return '0'.substr($phone, 2);
        }

        if (! str_starts_with($phone, '0') && strlen($phone) === 10) {
            return '0'.$phone;
        }

        return $phone;
    }

    private function logToConsole(string $line): void
    {
        Log::info($line);

        if (! app()->isProduction()) {
            error_log($line);
            file_put_contents('php://stderr', $line.PHP_EOL, FILE_APPEND);
        }
    }
}
