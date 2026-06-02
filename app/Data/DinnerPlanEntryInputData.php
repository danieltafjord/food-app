<?php

namespace App\Data;

use App\Enums\MealType;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

class DinnerPlanEntryInputData extends Data
{
    public function __construct(
        public int $dinnerId,
        #[Date]
        public string $scheduledDate,
        #[Min(1)]
        public int $servings,
        public MealType $mealType = MealType::Dinner,
        #[Max(255)]
        public ?string $notes = null,
    ) {}
}
