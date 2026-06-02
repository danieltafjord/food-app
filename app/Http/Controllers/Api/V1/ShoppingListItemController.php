<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ShoppingLists\AddShoppingListItem;
use App\Actions\ShoppingLists\UpdateShoppingListItem;
use App\Data\CheckItemInputData;
use App\Data\ShoppingListItemData;
use App\Data\ShoppingListItemInputData;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShoppingListItemController extends ApiController
{
    public function store(ShoppingListItemInputData $data, Request $request, ShoppingList $shoppingList, AddShoppingListItem $action): ShoppingListItemData
    {
        $this->ensureBelongsToHousehold($request, $shoppingList);

        return ShoppingListItemData::fromItem($action->handle($shoppingList, $data));
    }

    public function update(ShoppingListItemInputData $data, Request $request, ShoppingList $shoppingList, ShoppingListItem $item, UpdateShoppingListItem $action): ShoppingListItemData
    {
        $this->ensureBelongsToHousehold($request, $shoppingList);
        $this->ensureItemBelongs($shoppingList, $item);

        return ShoppingListItemData::fromItem($action->handle($item, $data));
    }

    public function check(CheckItemInputData $data, Request $request, ShoppingList $shoppingList, ShoppingListItem $item): ShoppingListItemData
    {
        $this->ensureBelongsToHousehold($request, $shoppingList);
        $this->ensureItemBelongs($shoppingList, $item);

        $item->update(['is_checked' => $data->checked]);

        return ShoppingListItemData::fromItem($item->load('ingredient'));
    }

    public function destroy(Request $request, ShoppingList $shoppingList, ShoppingListItem $item): Response
    {
        $this->ensureBelongsToHousehold($request, $shoppingList);
        $this->ensureItemBelongs($shoppingList, $item);

        $item->delete();

        return response()->noContent();
    }

    private function ensureItemBelongs(ShoppingList $shoppingList, ShoppingListItem $item): void
    {
        abort_unless($item->shopping_list_id === $shoppingList->id, 404);
    }
}
