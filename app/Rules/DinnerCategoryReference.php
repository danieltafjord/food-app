<?php

namespace App\Rules;

use App\Enums\DinnerCategory;
use App\Models\HouseholdDinnerCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class DinnerCategoryReference implements ValidationRule
{
    public function __construct(private int $householdId, private bool $allowDeleted = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && DinnerCategory::tryFrom($value) !== null) {
            return;
        }
        // PostgreSQL rejects a non-UUID compared with a uuid column outright.
        if (! is_string($value) || ! Str::isUuid($value)) {
            $fail('Choose a category from this household.');

            return;
        }
        $query = HouseholdDinnerCategory::query()->where('household_id', $this->householdId)->where('uuid', $value);
        if ($this->allowDeleted) {
            $query->withTrashed();
        }
        if (! $query->exists()) {
            $fail('Choose a category from this household.');
        }
    }
}
