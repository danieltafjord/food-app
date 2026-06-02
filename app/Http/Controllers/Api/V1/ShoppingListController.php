<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\ShoppingListData;
use App\Data\ShoppingListInputData;
use App\Models\Household;
use App\Models\ShoppingList;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\DataCollection;

class ShoppingListController extends ApiController
{
    public function index(Request $request): DataCollection
    {
        $lists = $this->currentHousehold($request)->shoppingLists()
            ->with('items.ingredient')
            ->latest()
            ->get()
            ->map(fn (ShoppingList $list) => ShoppingListData::fromList($list));

        return ShoppingListData::collect($lists, DataCollection::class);
    }

    public function store(ShoppingListInputData $data, Request $request): ShoppingListData
    {
        $household = $this->currentHousehold($request);
        $this->assertPlanBelongsToHousehold($household, $data->dinnerPlanId);

        $list = $household->shoppingLists()->create([
            'name' => $data->name,
            'dinner_plan_id' => $data->dinnerPlanId,
            'created_by_user_id' => $request->user()->id,
        ]);

        return ShoppingListData::fromList($list->load('items.ingredient'));
    }

    public function show(Request $request, ShoppingList $shoppingList): ShoppingListData
    {
        $this->ensureBelongsToHousehold($request, $shoppingList);

        return ShoppingListData::fromList($shoppingList->load('items.ingredient'));
    }

    public function update(ShoppingListInputData $data, Request $request, ShoppingList $shoppingList): ShoppingListData
    {
        $this->ensureBelongsToHousehold($request, $shoppingList);
        $this->assertPlanBelongsToHousehold($this->currentHousehold($request), $data->dinnerPlanId);

        $shoppingList->update([
            'name' => $data->name,
            'dinner_plan_id' => $data->dinnerPlanId,
        ]);

        return ShoppingListData::fromList($shoppingList->load('items.ingredient'));
    }

    public function destroy(Request $request, ShoppingList $shoppingList): Response
    {
        $this->ensureBelongsToHousehold($request, $shoppingList);

        $shoppingList->delete();

        return response()->noContent();
    }

    private function assertPlanBelongsToHousehold(Household $household, ?int $planId): void
    {
        if (! is_null($planId) && ! $household->dinnerPlans()->whereKey($planId)->exists()) {
            throw ValidationException::withMessages([
                'dinner_plan_id' => 'That plan does not belong to this household.',
            ]);
        }
    }
}
