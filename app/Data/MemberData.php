<?php

namespace App\Data;

use App\Enums\HouseholdRole;
use App\Models\User;
use Spatie\LaravelData\Data;

class MemberData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public HouseholdRole $role,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: HouseholdRole::from($user->pivot->role),
        );
    }
}
