<?php

namespace App\Data;

use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

class DinnerPlanData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?CarbonImmutable $startDate,
        public ?CarbonImmutable $endDate,
        public array $entries,
    ) {}

    public static function fromPlan(DinnerPlan $plan): self
    {
        return new self(
            id: $plan->id,
            name: $plan->name,
            startDate: $plan->start_date,
            endDate: $plan->end_date,
            entries: $plan->entries
                ->map(fn (DinnerPlanEntry $entry) => DinnerPlanEntryData::fromEntry($entry)->toArray())
                ->all(),
        );
    }
}
