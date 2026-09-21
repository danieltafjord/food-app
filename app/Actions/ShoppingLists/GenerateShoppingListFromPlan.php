<?php

namespace App\Actions\ShoppingLists;

use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GenerateShoppingListFromPlan
{
    /**
     * Build a shopping list from a dinner plan: scale each recipe item by the
     * planned servings (entry.servings / dinner.default_servings) and sum the
     * results, grouped by ingredient + unit.
     */
    /**
     * Units compare trimmed and lower-cased; blank means "no unit". Mirrored by
     * the mobile client's `normalizeUnit` so both sides aggregate identically.
     */
    public static function normalizeUnit(?string $unit): ?string
    {
        $normalized = mb_strtolower(trim((string) $unit));

        return $normalized === '' ? null : $normalized;
    }

    public function handle(DinnerPlan $plan, User $user): ShoppingList
    {
        $plan->loadMissing('household');
        // Offline devices can create distinct plans for one week. Match the mobile board.
        $planIds = $plan->start_date === null ? [$plan->id] : $plan->household->dinnerPlans()
            ->whereDate('start_date', $plan->start_date->toDateString())->pluck('id')->all();
        $entries = DinnerPlanEntry::query()->whereIn('dinner_plan_id', $planIds)->with('dinner.items')->get();

        $aggregated = [];

        foreach ($entries as $entry) {
            $dinner = $entry->dinner;

            if ($dinner === null) {
                continue;
            }

            $factor = $dinner->default_servings > 0
                ? $entry->servings / $dinner->default_servings
                : 1.0;

            foreach ($dinner->items->sortByDesc('updated_at')->unique(fn ($item) => $item->ingredient_id.'|'.(self::normalizeUnit($item->unit) ?? '')) as $item) {
                $unit = self::normalizeUnit($item->unit);
                $key = $item->ingredient_id.'|'.($unit ?? '');
                $scaled = $item->quantity !== null ? (float) $item->quantity * $factor : null;

                if (! array_key_exists($key, $aggregated)) {
                    $aggregated[$key] = [
                        'ingredient_id' => $item->ingredient_id,
                        'unit' => $unit,
                        'quantity' => $scaled,
                        'sources' => [$item],
                    ];
                } else {
                    $aggregated[$key]['sources'][] = $item;
                    if ($scaled !== null) {
                        $aggregated[$key]['quantity'] = ($aggregated[$key]['quantity'] ?? 0) + $scaled;
                    }
                }
            }
        }

        return DB::transaction(function () use ($plan, $user, $aggregated): ShoppingList {
            $list = $plan->household->shoppingLists()->make([
                'dinner_plan_id' => $plan->id,
                'created_by_user_id' => $user->id,
                'name' => $plan->name,
            ]);
            $list->attributeContentTo($user->id)->inheritContentAuthors($plan, ['name' => 'name'])->save();

            foreach ($aggregated as $row) {
                $item = $list->items()->make([
                    'ingredient_id' => $row['ingredient_id'],
                    'is_generated' => true,
                    'quantity' => $row['quantity'] !== null ? round($row['quantity'], 2) : null,
                    'unit' => $row['unit'],
                ]);
                foreach ($row['sources'] as $source) {
                    $item->inheritContentAuthors($source, ['unit' => 'unit', 'quantity' => 'quantity']);
                }
                $item->attributeContentTo($user->id)->save();
            }

            return $list->load('items.ingredient');
        });
    }
}
