<?php

namespace App\Actions\Ai;

/**
 * The outcome of one provider call: the cacheable payload plus usage metadata
 * that is recorded for analytics but never returned to clients.
 */
final readonly class AiResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $cost = 0.0,
    ) {}
}
