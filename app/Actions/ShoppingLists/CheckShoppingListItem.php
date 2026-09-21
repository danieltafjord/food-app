<?php

namespace App\Actions\ShoppingLists;

use App\Models\ShoppingListItem;

class CheckShoppingListItem
{
    public function handle(ShoppingListItem $item, bool $checked): ShoppingListItem
    {
        $item->update(['is_checked' => $checked]);

        return $item->load('ingredient');
    }
}
