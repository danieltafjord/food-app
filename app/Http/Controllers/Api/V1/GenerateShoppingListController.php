<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ShoppingLists\GenerateShoppingListFromPlan;
use App\Data\ShoppingListData;
use App\Models\DinnerPlan;
use Illuminate\Http\Request;

class GenerateShoppingListController extends ApiController
{
    /**
     * Generate a shopping list from a dinner plan.
     */
    public function __invoke(Request $request, DinnerPlan $dinnerPlan, GenerateShoppingListFromPlan $action): ShoppingListData
    {
        $this->ensureBelongsToHousehold($request, $dinnerPlan);

        return ShoppingListData::fromList($action->handle($dinnerPlan, $request->user()));
    }
}
