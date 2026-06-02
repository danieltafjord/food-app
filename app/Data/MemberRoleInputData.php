<?php

namespace App\Data;

use App\Enums\HouseholdRole;
use Spatie\LaravelData\Data;

class MemberRoleInputData extends Data
{
    public function __construct(
        public HouseholdRole $role,
    ) {}
}
