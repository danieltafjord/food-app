<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ShoppingLists\CreateShoppingList;
use App\Actions\ShoppingLists\UpdateShoppingList;
use App\Data\ShoppingListData;
use App\Data\ShoppingListInputData;
use App\Models\ShoppingList;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\DataCollection;

class ShoppingListController extends ApiController
{
    /**
     * @return DataCollection<int, ShoppingListData>
     */
    public function index(Request $request): DataCollection
    {
        $lists = $this->currentHousehold($request)->shoppingLists()
            ->with('items.ingredient')
            ->latest()
            ->get()
            ->map(fn (ShoppingList $list) => ShoppingListData::fromList($list));

        return ShoppingListData::collect($lists, DataCollection::class);
    }

    public function store(ShoppingListInputData $data, Request $request, CreateShoppingList $action): ShoppingListData
    {
        return ShoppingListData::fromList($action->handle($this->currentHousehold($request), $data, $request->user()));
    }

    public function show(Request $request, ShoppingList $shoppingList): ShoppingListData
    {
        $this->ensureBelongsToHousehold($request, $shoppingList);

        return ShoppingListData::fromList($shoppingList->load('items.ingredient'));
    }

    public function update(ShoppingListInputData $data, Request $request, ShoppingList $shoppingList, UpdateShoppingList $action): ShoppingListData
    {
        $this->ensureBelongsToHousehold($request, $shoppingList);

        return ShoppingListData::fromList($action->handle($shoppingList, $data));
    }

    public function destroy(Request $request, ShoppingList $shoppingList): Response
    {
        $this->ensureBelongsToHousehold($request, $shoppingList);

        $shoppingList->delete();

        return response()->noContent();
    }
}
