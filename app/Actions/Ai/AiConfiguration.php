<?php

namespace App\Actions\Ai;

use App\Enums\ReasoningEffort;
use App\Models\AppSetting;

/**
 * Resolves which OpenRouter model and reasoning effort each AI feature uses.
 * Admins override the `.env` defaults at runtime through the app settings store.
 */
class AiConfiguration
{
    /** @var array<string, array{label: string, description: string, config: string, supports_reasoning: bool}> */
    public const FEATURES = [
        'categorization' => [
            'label' => 'Ingredient categorization',
            'description' => 'Picks the shopping aisle for a new ingredient. Uses the System One endpoint, so only System One models (for example typesafe/jev-1.13) work here and reasoning effort does not apply.',
            'config' => 'classification',
            'supports_reasoning' => false,
        ],
        'suggestions' => [
            'label' => 'Dinner ingredient suggestions',
            'description' => 'Suggests up to three missing ingredients for a dinner through chat completions with structured output.',
            'config' => 'suggestion',
            'supports_reasoning' => true,
        ],
    ];

    public const LIMIT_SCOPES = ['user', 'household', 'global'];

    /** The environment supplies the initial value until an admin saves an override. */
    public function enabled(): bool
    {
        return (bool) AppSetting::get('ai.enabled', (bool) config('assistance.enabled'));
    }

    public function setEnabled(bool $enabled): void
    {
        AppSetting::set('ai.enabled', $enabled);
    }

    public function providerConfigured(): bool
    {
        return filled(config('ai.providers.openrouter.key'));
    }

    public function model(string $feature): string
    {
        $model = AppSetting::get("ai.{$feature}.model");

        return is_string($model) && $model !== '' ? $model : (string) config('assistance.'.self::FEATURES[$feature]['config'].'_model');
    }

    public function reasoningEffort(string $feature): ?ReasoningEffort
    {
        if (! self::FEATURES[$feature]['supports_reasoning']) {
            return null;
        }
        $stored = AppSetting::get("ai.{$feature}.reasoning", config('assistance.'.self::FEATURES[$feature]['config'].'_reasoning'));

        return is_string($stored) ? ReasoningEffort::tryFrom($stored) : null;
    }

    public function update(string $feature, string $model, ?ReasoningEffort $effort): void
    {
        AppSetting::set("ai.{$feature}.model", $model);
        AppSetting::set("ai.{$feature}.reasoning", $effort?->value);
    }

    /** Daily request budget for one feature and scope (`user`, `household` or `global`). */
    public function limit(string $feature, string $kind): int
    {
        $stored = AppSetting::get("ai.{$feature}.limit.{$kind}");

        return max(0, (int) (is_int($stored) ? $stored : config("assistance.limits.{$feature}.{$kind}")));
    }

    /** @param  array<string, int>  $limits keyed by scope */
    public function updateLimits(string $feature, array $limits): void
    {
        foreach (self::LIMIT_SCOPES as $kind) {
            if (array_key_exists($kind, $limits)) {
                AppSetting::set("ai.{$feature}.limit.{$kind}", max(0, (int) $limits[$kind]));
            }
        }
    }

    /** @return list<array{feature: string, label: string, user: int, household: int, global: int, defaults: array{user: int, household: int, global: int}}> */
    public function limits(): array
    {
        $rows = [];
        foreach (self::FEATURES as $key => $feature) {
            $row = ['feature' => $key, 'label' => $feature['label'], 'defaults' => []];
            foreach (self::LIMIT_SCOPES as $kind) {
                $row[$kind] = $this->limit($key, $kind);
                $row['defaults'][$kind] = max(0, (int) config("assistance.limits.{$key}.{$kind}"));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<array{key: string, label: string, description: string, model: string, reasoning: ?string, supports_reasoning: bool, default_model: string}> */
    public function all(): array
    {
        $features = [];
        foreach (self::FEATURES as $key => $feature) {
            $features[] = [
                'key' => $key,
                'label' => $feature['label'],
                'description' => $feature['description'],
                'model' => $this->model($key),
                'reasoning' => $this->reasoningEffort($key)?->value,
                'supports_reasoning' => $feature['supports_reasoning'],
                'default_model' => (string) config('assistance.'.$feature['config'].'_model'),
            ];
        }

        return $features;
    }
}
