<?php

/**
 * ONE-TIME production installer for shared hosting (no SSH).
 * Upload to: public_html/public/deploy-install.php
 * Open: https://mdbackend.demitkhorma.ir/deploy-install.php?key=YOUR_SECRET
 * DELETE this file immediately after success.
 */

declare(strict_types=1);

$secret = 'demit-install-2026-change-me';

if (! isset($_GET['key']) || ! hash_equals($secret, (string) $_GET['key'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$run = static function (string $command) use ($kernel): void {
    echo ">>> php artisan {$command}\n";
    $exit = $kernel->call($command);
    echo $kernel->output();
    echo "exit={$exit}\n\n";
    if ($exit !== 0) {
        throw new RuntimeException("Command failed: {$command}");
    }
};

try {
    $run('migrate --force');
    $run('db:seed --force');
    $run('storage:link');
    $run('config:cache');
    $run('route:cache');

    echo "OK — installation finished.\n";
    echo "DELETE public/deploy-install.php NOW.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: ".$e->getMessage()."\n";
}
