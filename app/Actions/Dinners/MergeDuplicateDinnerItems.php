<?php

namespace App\Actions\Dinners;

use App\Actions\ShoppingLists\GenerateShoppingListFromPlan;
use App\Actions\Sync\SyncBatchState;
use App\Models\DinnerItem;
use App\Models\Household;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class MergeDuplicateDinnerItems
{
    /** Called under the household lock. The oldest identity retains the newest content. */
    public function handle(Household $household, SyncBatchState $state): void
    {
        $groups = DinnerItem::query()->whereIn('dinner_id', $household->dinners()->select('id'))
            ->orderBy('id')->get()->groupBy(fn (DinnerItem $item) => $item->dinner_id.':'.$item->ingredient_id.':'.(GenerateShoppingListFromPlan::normalizeUnit($item->unit) ?? ''));
        foreach ($groups as $items) {
            if ($items->count() < 2) {
                continue;
            }
            $survivor = $items->first();
            $newest = $items->sortByDesc(fn (DinnerItem $item) => $item->updated_at?->toISOString().'|'.str_pad((string) $item->id, 20, '0', STR_PAD_LEFT))->first();
            $survivor->forceFill(['quantity' => $newest->quantity, 'unit' => $newest->unit, 'updated_at' => $newest->updated_at, 'erasure_version' => $items->max('erasure_version')]);
            foreach ($items as $item) {
                $survivor->inheritContentAuthors($item, ['quantity' => 'quantity', 'unit' => 'unit']);
            }
            $survivor->withoutContentAttribution()->stampSync($state->version());
            Model::withoutTimestamps(fn () => $survivor->save());
            $state->include('dinner_items', $survivor->uuid);
            foreach ($items->skip(1) as $duplicate) {
                $duplicate->forceFill(['merged_into_uuid' => $survivor->uuid]);
                $duplicate->tombstone(CarbonImmutable::now(), $state->version());
                $state->remaps['dinner_items'][$duplicate->uuid] = $survivor->uuid;
                $state->include('dinner_items', $duplicate->uuid);
            }
        }
    }
}
