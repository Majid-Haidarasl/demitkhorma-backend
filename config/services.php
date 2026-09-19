<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    'otp' => [
        // email | sms | both — customer OTP. Admin recovery always uses email when available.
        'channel' => env('OTP_CHANNEL', 'sms'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'smsir'),
        'kavenegar' => [
            'api_key' => env('KAVENEGAR_API_KEY'),
            'sender' => env('KAVENEGAR_SENDER'),
        ],
        'smsir' => [
            'api_key' => env('SMSIR_API_KEY'),
            'template_id' => env('SMSIR_TEMPLATE_ID'),
            'template_param' => env('SMSIR_TEMPLATE_PARAM', 'Code'),
            'line_number' => env('SMSIR_LINE_NUMBER'),
            'timeout' => env('SMSIR_TIMEOUT', 30),
        ],
    ],

    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        'sandbox' => env('ZARINPAL_SANDBOX', true),
        // Store prices are in تومان; Zarinpal API expects ریال (×10).
        'amount_unit' => env('ZARINPAL_AMOUNT_UNIT', 'toman'),
    ],

    'checkout' => [
        // Temporarily COD: shipping is settled with the courier, not charged on the site.
        'shipping_cost' => 0,
    ],

];
