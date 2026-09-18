<?php

namespace App\Support;

class SafeInput
{
    public static function likeContains(?string $term, int $max = 80): ?string
    {
        $term = trim((string) $term);
        if ($term === '') {
            return null;
        }

        $term = mb_substr($term, 0, $max);

        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    public static function text(?string $value): string
    {
        return trim(strip_tags((string) $value));
    }

    public static function csvCell(mixed $value): string
    {
        $text = (string) $value;
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
    }

    public static function originalFilename(?string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '', (string) $name);
        $name = basename($name);

        return mb_substr($name, 0, 180);
    }
}
