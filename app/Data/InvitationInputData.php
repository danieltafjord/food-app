<?php

namespace App\Data;

use App\Enums\HouseholdRole;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class InvitationInputData extends Data
{
    public function __construct(
        #[Email, Max(255)]
        public string $email,
        public HouseholdRole $role = HouseholdRole::Member,
    ) {}
}
