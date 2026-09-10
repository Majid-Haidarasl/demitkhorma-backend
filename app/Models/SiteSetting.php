<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("setting.{$key}", 3600, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();

            return $setting?->value ?? $default;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting.{$key}");
        Cache::forget('settings.public');
    }

    public static function allPublic(): array
    {
        $allowed = [
            'site_name',
            'site_tagline',
            'phone',
            'email',
            'contact_email',
            'address',
            'contact_address',
            'contact_hours',
            'instagram',
            'instagram_url',
            'telegram',
            'telegram_url',
            'whatsapp',
            'shipping_note',
            'footer_text',
        ];

        return Cache::remember('settings.public', 3600, function () use ($allowed) {
            return static::query()
                ->whereIn('key', $allowed)
                ->pluck('value', 'key')
                ->toArray();
        });
    }
}
