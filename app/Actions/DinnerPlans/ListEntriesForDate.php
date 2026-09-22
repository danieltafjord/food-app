<?php

namespace App\Actions\DinnerPlans;

use App\Models\DinnerPlanEntry;
use App\Models\Household;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ListEntriesForDate
{
    /**
     * Everything the household has planned for the given day, across plans.
     *
     * @return Collection<int, DinnerPlanEntry>
     */
    public function handle(Household $household, CarbonInterface $date): Collection
    {
        return $this->query($household, $date)->get();
    }

    /** @return Builder<DinnerPlanEntry> */
    public function query(Household $household, CarbonInterface $date): Builder
    {
        return DinnerPlanEntry::query()
            ->with('dinner')
            ->whereIn('dinner_plan_id', $household->dinnerPlans()->select('id'))
            ->whereDate('scheduled_date', $date)
            ->orderBy('meal_type');
    }
}
