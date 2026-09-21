<?php

namespace App\Mcp\Tools;

use App\Actions\ShoppingLists\AddShoppingListItem;
use App\Data\ShoppingListItemData;
use App\Data\ShoppingListItemInputData;
use App\Enums\ApiTokenScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Add an item to a shopping list, either free-text by name or by ingredient id from the catalogue.')]
#[IsIdempotent(false)]
class AddShoppingListItemTool extends HouseholdTool
{
    protected ApiTokenScope $requiredScope = ApiTokenScope::Write;

    public function handle(Request $request, AddShoppingListItem $action): Response
    {
        $validated = $request->validate(['shopping_list_id' => ['required', 'integer']]);
        $list = $this->household()->shoppingLists()->findOrFail($validated['shopping_list_id']);

        $item = $action->handle($list, ShoppingListItemInputData::validateAndCreate(Arr::except($request->all(), 'shopping_list_id')));

        return Response::json(ShoppingListItemData::fromItem($item)->toArray());
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'shopping_list_id' => $schema->integer()->description('Id of the shopping list.')->required(),
            'name' => $schema->string()->description('Free-text item name. Required unless ingredient_id is given.'),
            'ingredient_id' => $schema->integer()->description('Id of a catalogue ingredient.'),
            'quantity' => $schema->number()->description('How much to buy.'),
            'unit' => $schema->string()->description('Unit for the quantity, e.g. "g" or "pcs".'),
        ];
    }
}
