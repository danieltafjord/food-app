<?php

namespace App\Data;

use App\Enums\HouseholdRole;
use App\Models\HouseholdInvitation;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

class InvitationData extends Data
{
    public function __construct(
        public int $id,
        public string $email,
        public HouseholdRole $role,
        public string $status,
        public ?CarbonImmutable $expiresAt,
    ) {}

    public static function fromInvitation(HouseholdInvitation $invitation): self
    {
        return new self(
            id: $invitation->id,
            email: $invitation->email,
            role: $invitation->role,
            status: $invitation->status(),
            expiresAt: $invitation->expires_at,
        );
    }
}
