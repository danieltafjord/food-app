<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value store for settings admins change at runtime. Values are cached
 * per key; use the static helpers so the cache stays consistent.
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // Memoized for the request too: a page or AI call reads many settings.
        $stored = Cache::memo()->rememberForever(self::cacheKey($key), function () use ($key): array {
            $setting = self::query()->find($key);

            return ['exists' => $setting !== null, 'value' => $setting?->value];
        });

        return $stored['exists'] ? $stored['value'] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::memo()->forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'app-setting:'.$key;
    }
}
