<?php

namespace App\Actions\ShoppingLists;

use App\Data\ShoppingListInputData;
use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;

class CreateShoppingList
{
    use EnsuresPlanBelongsToHousehold;

    public function handle(Household $household, ShoppingListInputData $data, User $creator): ShoppingList
    {
        $this->assertPlanBelongsToHousehold($household, $data->dinnerPlanId);

        return $household->shoppingLists()->create([
            'name' => $data->name,
            'dinner_plan_id' => $data->dinnerPlanId,
            'created_by_user_id' => $creator->id,
        ])->load('items.ingredient');
    }
}
