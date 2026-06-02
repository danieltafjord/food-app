<?php

namespace App\Data;

use App\Enums\HouseholdRole;
use App\Models\Household;
use Spatie\LaravelData\Data;

class HouseholdMembershipData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public HouseholdRole $role,
    ) {}

    public static function fromHousehold(Household $household): self
    {
        return new self(
            id: $household->id,
            name: $household->name,
            role: HouseholdRole::from($household->pivot->role),
        );
    }
}
