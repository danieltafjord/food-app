<?php

namespace App\Data;

use Carbon\CarbonImmutable;
use Laravel\Passport\Token;
use Spatie\LaravelData\Data;

class AccessTokenData extends Data
{
    public function __construct(
        public string $id,
        public ?string $name,
        public bool $current,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $expiresAt,
    ) {}

    public static function fromToken(Token $token, ?string $currentTokenId = null): self
    {
        return new self(
            id: $token->id,
            name: $token->name,
            current: $token->id === $currentTokenId,
            createdAt: $token->created_at,
            expiresAt: $token->expires_at,
        );
    }
}
