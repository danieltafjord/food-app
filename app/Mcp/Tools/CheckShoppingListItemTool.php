<?php

namespace App\Mcp\Tools;

use App\Actions\ShoppingLists\CheckShoppingListItem;
use App\Data\ShoppingListItemData;
use App\Enums\ApiTokenScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Check off (or uncheck) an item on a shopping list.')]
#[IsIdempotent]
class CheckShoppingListItemTool extends HouseholdTool
{
    protected ApiTokenScope $requiredScope = ApiTokenScope::Write;

    public function handle(Request $request, CheckShoppingListItem $action): Response
    {
        $validated = $request->validate([
            'shopping_list_id' => ['required', 'integer'],
            'item_id' => ['required', 'integer'],
            'checked' => ['required', 'boolean'],
        ]);

        $item = $this->household()->shoppingLists()->findOrFail($validated['shopping_list_id'])
            ->items()->findOrFail($validated['item_id']);

        return Response::json(ShoppingListItemData::fromItem($action->handle($item, $validated['checked']))->toArray());
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
            'item_id' => $schema->integer()->description('Id of the item on that list.')->required(),
            'checked' => $schema->boolean()->description('True to check the item off, false to uncheck it.')->required(),
        ];
    }
}
