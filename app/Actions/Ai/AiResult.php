<?php

namespace App\Actions\Ai;

/**
 * The outcome of one provider call: the cacheable payload plus usage metadata
 * and the provider's raw answer, which are recorded for the admin request log
 * but never returned to clients.
 */
final readonly class AiResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $raw  What the provider answered before validation and filtering.
     */
    public function __construct(
        public array $data,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $cost = 0.0,
        public ?array $raw = null,
    ) {}
}
