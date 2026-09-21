<?php

namespace App\Actions\ShoppingLists;

use App\Data\ShoppingListInputData;
use App\Models\ShoppingList;

class UpdateShoppingList
{
    use EnsuresPlanBelongsToHousehold;

    public function handle(ShoppingList $list, ShoppingListInputData $data): ShoppingList
    {
        $this->assertPlanBelongsToHousehold($list->household, $data->dinnerPlanId);

        $list->update([
            'name' => $data->name,
            'dinner_plan_id' => $data->dinnerPlanId,
        ]);

        return $list->load('items.ingredient');
    }
}
