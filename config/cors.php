<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_unique([
        env('FRONTEND_URL', 'http://localhost:5173'),
        ...array_filter(array_map('trim', explode(',', (string) env('CORS_EXTRA_ORIGINS', '')))),
        ...(env('APP_ENV', 'production') === 'local'
            ? [
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'http://localhost:5174',
                'http://127.0.0.1:5174',
            ]
            : []),
    ]))),

    // Native Flutter apps do not use browser CORS. These patterns help Flutter Web / local tools.
    'allowed_origins_patterns' => array_values(array_filter([
        env('APP_ENV', 'production') === 'local'
            ? '#^https?://(localhost|127\.0\.0\.1):\d+$#'
            : null,
        // Flutter web / chrome local ports
        '#^http://(localhost|127\.0\.0\.1):\d+$#',
    ])),
    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
