<?php

namespace App\Data;

use App\Models\ApiTokenDetail;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

class ApiTokenData extends Data
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public int $id,
        public ?string $name,
        public int $householdId,
        public ?string $householdName,
        public array $scopes,
        public ?CarbonImmutable $lastUsedAt,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $expiresAt,
    ) {}

    public static function fromApiToken(ApiTokenDetail $apiToken): self
    {
        return new self(
            id: $apiToken->id,
            name: $apiToken->token?->name,
            householdId: $apiToken->household_id,
            householdName: $apiToken->household?->name,
            scopes: $apiToken->token?->scopes ?? [],
            lastUsedAt: $apiToken->last_used_at,
            createdAt: $apiToken->created_at,
            expiresAt: $apiToken->token?->expires_at,
        );
    }
}
