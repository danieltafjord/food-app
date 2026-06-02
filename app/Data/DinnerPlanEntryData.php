<?php

namespace App\Data;

use App\Enums\MealType;
use App\Models\DinnerPlanEntry;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

class DinnerPlanEntryData extends Data
{
    public function __construct(
        public int $id,
        public int $dinnerId,
        public ?string $dinnerName,
        public CarbonImmutable $scheduledDate,
        public int $servings,
        public MealType $mealType,
        public ?string $notes,
    ) {}

    public static function fromEntry(DinnerPlanEntry $entry): self
    {
        return new self(
            id: $entry->id,
            dinnerId: $entry->dinner_id,
            dinnerName: $entry->dinner?->name,
            scheduledDate: $entry->scheduled_date,
            servings: $entry->servings,
            mealType: $entry->meal_type,
            notes: $entry->notes,
        );
    }
}
