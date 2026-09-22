<?php

namespace App\Actions\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The public OpenRouter model catalogue, trimmed to what the admin model
 * picker needs and cached for an hour. A failed fetch is remembered for only
 * a minute so the picker recovers quickly.
 */
class ListOpenRouterModels
{
    public const URL = 'https://openrouter.ai/api/v1/models';

    private const CACHE_KEY = 'openrouter:models';

    private const TTL_OK = 3600;

    private const TTL_FAILED = 60;

    /** @return list<array{id: string, name: string, context_length: ?int, prompt_price: ?float, completion_price: ?float, supports_reasoning: bool}> */
    public function handle(): array
    {
        return $this->cached()['models'];
    }

    /**
     * When the catalogue was last fetched and whether that fetch failed.
     *
     * @return array{fetched_at: ?string, failed: bool}
     */
    public function status(): array
    {
        $cached = $this->cached();

        return ['fetched_at' => $cached['fetched_at'], 'failed' => $cached['failed']];
    }

    /** Drop the cached copy so the next call fetches a fresh catalogue. */
    public function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array{models: list<array<string, mixed>>, fetched_at: ?string, failed: bool} */
    private function cached(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && array_key_exists('models', $cached)) {
            return $cached;
        }

        $fetched = $this->fetch();
        Cache::put(self::CACHE_KEY, $fetched, $fetched['failed'] ? self::TTL_FAILED : self::TTL_OK);

        return $fetched;
    }

    /** @return array{models: list<array<string, mixed>>, fetched_at: ?string, failed: bool} */
    private function fetch(): array
    {
        try {
            $models = Http::acceptJson()->connectTimeout(3)->timeout(15)->get(self::URL)->throw()->json('data');
        } catch (Throwable) {
            return ['models' => [], 'fetched_at' => now()->toIso8601String(), 'failed' => true];
        }
        $result = [];
        foreach (is_array($models) ? $models : [] as $model) {
            if (! is_array($model) || ! is_string($model['id'] ?? null)) {
                continue;
            }
            $result[] = [
                'id' => $model['id'],
                'name' => is_string($model['name'] ?? null) ? $model['name'] : $model['id'],
                'context_length' => isset($model['context_length']) ? (int) $model['context_length'] : null,
                'prompt_price' => self::pricePerMillion($model['pricing']['prompt'] ?? null),
                'completion_price' => self::pricePerMillion($model['pricing']['completion'] ?? null),
                'supports_reasoning' => in_array('reasoning', $model['supported_parameters'] ?? [], true),
            ];
        }
        usort($result, fn (array $a, array $b) => strcmp($a['id'], $b['id']));

        return ['models' => $result, 'fetched_at' => now()->toIso8601String(), 'failed' => false];
    }

    /** OpenRouter prices are USD per token; the picker shows USD per million tokens. */
    private static function pricePerMillion(mixed $price): ?float
    {
        return is_numeric($price) ? round((float) $price * 1_000_000, 4) : null;
    }
}
