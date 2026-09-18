<?php

/**
 * One-time public storage setup for hosts that disable symlink().
 * Upload to: public_html/storage-link.php
 * Open the URL, then DELETE this file.
 */

declare(strict_types=1);

$secret = 'demit-storage-link-2026';

header('Content-Type: text/plain; charset=utf-8');

if (! isset($_GET['key']) || ! hash_equals($secret, (string) $_GET['key'])) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$publicStorage = __DIR__.'/storage';
$laravelPublic = realpath(__DIR__.'/../laravel/storage/app/public') ?: __DIR__.'/../laravel/storage/app/public';
$oldStorage = __DIR__.'/storage-old';

$copyTree = static function (string $from, string $to) use (&$copyTree): int {
    if (! is_dir($from)) {
        return 0;
    }
    if (! is_dir($to) && ! mkdir($to, 0755, true) && ! is_dir($to)) {
        throw new RuntimeException('Cannot create '.$to);
    }

    $copied = 0;
    $items = scandir($from);
    if ($items === false) {
        return 0;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $src = $from.DIRECTORY_SEPARATOR.$item;
        $dest = $to.DIRECTORY_SEPARATOR.$item;

        if (is_dir($src)) {
            $copied += $copyTree($src, $dest);
            continue;
        }

        if (! is_file($src)) {
            continue;
        }

        if (! file_exists($dest) && copy($src, $dest)) {
            $copied++;
        }
    }

    return $copied;
};

echo "public storage: {$publicStorage}\n";
echo "laravel source: {$laravelPublic}\n";

if (is_link($publicStorage)) {
    echo "FAILED: storage is a link; this host cannot use links. Delete it and retry.\n";
    exit;
}

if (file_exists($publicStorage) && ! is_dir($publicStorage)) {
    echo "FAILED: public_html/storage exists and is not a folder\n";
    exit;
}

if (! is_dir($publicStorage) && ! mkdir($publicStorage, 0755, true) && ! is_dir($publicStorage)) {
    echo "FAILED: cannot create public_html/storage\n";
    exit;
}

try {
    $fromLaravel = $copyTree($laravelPublic, $publicStorage);
    $fromOld = $copyTree($oldStorage, $publicStorage);
} catch (Throwable $e) {
    echo 'FAILED: '.$e->getMessage()."\n";
    exit;
}

echo "copied from laravel: {$fromLaravel}\n";
echo "copied from storage-old: {$fromOld}\n";
echo "OK — public_html/storage is ready (no symlink)\n";
echo "DELETE public_html/storage-link.php now.\n";
