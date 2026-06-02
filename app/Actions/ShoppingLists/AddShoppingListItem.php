<?php

namespace App\Actions\ShoppingLists;

use App\Data\ShoppingListItemInputData;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;

class AddShoppingListItem
{
    use ManagesShoppingListItems;

    public function handle(ShoppingList $list, ShoppingListItemInputData $data): ShoppingListItem
    {
        $this->assertItemIsValid($list->household, $data);

        return $list->items()->create($this->itemAttributes($data))->load('ingredient');
    }
}
