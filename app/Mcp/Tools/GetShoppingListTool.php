<?php

namespace App\Mcp\Tools;

use App\Data\ShoppingListItemData;
use App\Models\ShoppingListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get one household record and a page of its items. Follow next_cursor with the same shopping_list_id to read more.')]
#[IsReadOnly]
class GetShoppingListTool extends PaginatedHouseholdTool
{
    public function handle(Request $request): Response
    {
        $input = $request->validate(['shopping_list_id' => ['required', 'integer']]);
        $record = $this->household()->shoppingLists()->findOrFail($input['shopping_list_id']);
        $page = $this->page($request, $record->items()->with('ingredient'),
            fn (ShoppingListItem $row) => ShoppingListItemData::fromItem($row)->toArray(), searchable: false);

        return Response::json(['record' => ['id' => $record->id, 'name' => $record->name, 'dinner_plan_id' => $record->dinner_plan_id], ...$page]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['shopping_list_id' => $schema->integer()->required(), ...$this->pageSchema($schema, searchable: false)];
    }
}
