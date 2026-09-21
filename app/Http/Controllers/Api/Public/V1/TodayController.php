<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Actions\DinnerPlans\ListEntriesForDate;
use App\Data\DinnerPlanEntryData;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\DinnerPlanEntry;
use Illuminate\Http\Request;
use Spatie\LaravelData\DataCollection;

class TodayController extends ApiController
{
    /**
     * What the household has planned for today.
     *
     * @return DataCollection<int, DinnerPlanEntryData>
     */
    public function __invoke(Request $request, ListEntriesForDate $action): DataCollection
    {
        $entries = $action->handle($this->currentHousehold($request), today())
            ->map(fn (DinnerPlanEntry $entry) => DinnerPlanEntryData::fromEntry($entry));

        return DinnerPlanEntryData::collect($entries, DataCollection::class);
    }
}
