<?php

namespace App\Actions\ShoppingLists;

use App\Data\ShoppingListItemInputData;
use App\Models\ShoppingListItem;

class UpdateShoppingListItem
{
    use ManagesShoppingListItems;

    public function handle(ShoppingListItem $item, ShoppingListItemInputData $data): ShoppingListItem
    {
        $this->assertItemIsValid($item->shoppingList->household, $data);

        $item->update($this->itemAttributes($data));

        return $item->load('ingredient');
    }
}
