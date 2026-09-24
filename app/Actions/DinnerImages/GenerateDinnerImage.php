<?php

namespace App\Actions\DinnerImages;

use App\Actions\Ai\AiUsage;
use App\Actions\Ai\RunAiRequest;
use App\Http\Middleware\LogApiRequest;
use App\Models\AiRequest;
use App\Models\DinnerImage;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Generates a dinner picture through OpenRouter's image endpoint and stores it
 * like an upload. Every call is an explicit tap, so nothing is cached; each one
 * reserves the per-user, per-household and global daily counts, and the whole
 * feature stops for the day once the recorded provider cost reaches the budget.
 */
class GenerateDinnerImage
{
    public const FEATURE = 'images';

    public function __construct(private AiUsage $usage, private RunAiRequest $runner, private StoreDinnerImage $store) {}

    /**
     * @param  array{name: string, ingredients: list<string>, category: string|null}  $context
     */
    public function handle(User $user, Household $household, array $context): DinnerImage
    {
        if (! $user->hasVerifiedEmail()) {
            $this->usage->reject('verification_required', 403);
        }
        if (! $this->runner->available()) {
            $this->usage->reject('unavailable', 503);
        }
        if ($this->spentToday() >= (float) config('assistance.images.daily_budget')) {
            $this->usage->reject('daily_limit', 429, (int) now('UTC')->diffInSeconds(now('UTC')->addDay()->startOfDay()));
        }
        $this->usage->reserve($user, $household, self::FEATURE);

        $model = (string) config('assistance.images.model');
        $startedAt = hrtime(true);
        try {
            $response = Http::baseUrl(rtrim((string) config('ai.providers.openrouter.url', 'https://openrouter.ai/api/v1'), '/'))
                ->withToken((string) config('ai.providers.openrouter.key'))
                ->timeout(60)
                ->post('images', [
                    'model' => $model,
                    'prompt' => $this->prompt($context),
                    'aspect_ratio' => '1:1',
                    'resolution' => '1K',
                    'output_format' => 'jpeg',
                    'n' => 1,
                    'provider' => ['data_collection' => 'deny'],
                ])
                ->throw();
            $encoded = $response->json('data.0.b64_json');
            $binary = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($binary === false || $binary === '') {
                throw new RuntimeException('The provider returned no image.');
            }
            $image = $this->store->handle($binary, $household, $user, DinnerImage::SOURCE_AI);
        } catch (Throwable $e) {
            Log::warning('AI image generation failed.', ['exception' => $e::class]);
            $this->record($user, $household, $model, AiRequest::STATUS_FAILED, $context, $startedAt, error: $this->runner->describe($e));
            $this->usage->reject('unavailable', 503);
        }

        $cost = $response->json('usage.cost');
        $this->record($user, $household, $model, AiRequest::STATUS_OK, $context, $startedAt,
            cost: is_numeric($cost) ? (float) $cost : (float) config('assistance.images.estimated_cost'),
            usage: $response->json('usage'), path: $image->path);

        return $image;
    }

    /** @param  array{name: string, ingredients: list<string>, category: string|null}  $context */
    public function prompt(array $context): string
    {
        $prompt = 'Appetizing, realistic food photograph of a home-cooked dinner: "'.$context['name'].'"';
        if ($context['ingredients'] !== []) {
            $prompt .= ', made with '.implode(', ', array_slice($context['ingredients'], 0, 8));
        }
        if ($context['category'] === 'vegetarian') {
            $prompt .= '. Vegetarian, no meat or fish';
        }

        return $prompt.'. Served on a simple plate, seen from slightly above, soft natural daylight, '
            .'shallow depth of field, clean neutral background. No text, no labels, no people, no hands.';
    }

    private function spentToday(): float
    {
        return (float) AiRequest::query()
            ->where('feature', self::FEATURE)
            ->where('created_at', '>=', now('UTC')->startOfDay())
            ->sum('cost');
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $usage
     */
    private function record(User $user, Household $household, string $model, string $status, array $context, int $startedAt, float $cost = 0.0, ?array $usage = null, ?string $path = null, ?string $error = null): void
    {
        AiRequest::query()->create([
            'request_id' => request()->attributes->get(LogApiRequest::REQUEST_ID),
            'user_id' => $user->id,
            'household_id' => $household->id,
            'feature' => self::FEATURE,
            'model' => $model,
            'status' => $status,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'cost' => $cost,
            'request' => [...$context, 'prompt' => $this->prompt($context)],
            'response' => $path === null ? null : ['path' => $path, 'usage' => $usage],
            'error' => $error,
            'created_at' => now(),
        ]);
    }
}
