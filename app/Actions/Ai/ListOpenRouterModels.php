<?php

namespace App\Actions\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The public OpenRouter model catalogue, trimmed to what the admin model
 * picker needs and cached for an hour.
 */
class ListOpenRouterModels
{
    public const URL = 'https://openrouter.ai/api/v1/models';

    /** @return list<array{id: string, name: string, context_length: ?int, prompt_price: ?float, completion_price: ?float, supports_reasoning: bool}> */
    public function handle(): array
    {
        return Cache::remember('openrouter:models', 3600, function (): array {
            try {
                $models = Http::acceptJson()->connectTimeout(3)->timeout(15)->get(self::URL)->throw()->json('data');
            } catch (Throwable) {
                return [];
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

            return $result;
        });
    }

    /** OpenRouter prices are USD per token; the picker shows USD per million tokens. */
    private static function pricePerMillion(mixed $price): ?float
    {
        return is_numeric($price) ? round((float) $price * 1_000_000, 4) : null;
    }
}
