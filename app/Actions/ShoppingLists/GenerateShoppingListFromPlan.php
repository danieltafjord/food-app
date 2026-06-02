<?php

namespace App\Actions\ShoppingLists;

use App\Models\DinnerPlan;
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
    public function handle(DinnerPlan $plan, User $user): ShoppingList
    {
        $plan->loadMissing('household', 'entries.dinner.items');

        $aggregated = [];

        foreach ($plan->entries as $entry) {
            $dinner = $entry->dinner;

            if ($dinner === null) {
                continue;
            }

            $factor = $dinner->default_servings > 0
                ? $entry->servings / $dinner->default_servings
                : 1.0;

            foreach ($dinner->items as $item) {
                $key = $item->ingredient_id.'|'.($item->unit ?? '');
                $scaled = $item->quantity !== null ? (float) $item->quantity * $factor : null;

                if (! array_key_exists($key, $aggregated)) {
                    $aggregated[$key] = [
                        'ingredient_id' => $item->ingredient_id,
                        'unit' => $item->unit,
                        'quantity' => $scaled,
                    ];
                } elseif ($scaled !== null) {
                    $aggregated[$key]['quantity'] = ($aggregated[$key]['quantity'] ?? 0) + $scaled;
                }
            }
        }

        return DB::transaction(function () use ($plan, $user, $aggregated): ShoppingList {
            $list = $plan->household->shoppingLists()->create([
                'dinner_plan_id' => $plan->id,
                'created_by_user_id' => $user->id,
                'name' => $plan->name,
            ]);

            foreach ($aggregated as $row) {
                $list->items()->create([
                    'ingredient_id' => $row['ingredient_id'],
                    'quantity' => $row['quantity'] !== null ? round($row['quantity'], 2) : null,
                    'unit' => $row['unit'],
                ]);
            }

            return $list->load('items.ingredient');
        });
    }
}
