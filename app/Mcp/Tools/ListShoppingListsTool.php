<?php

namespace App\Mcp\Tools;

use App\Models\ShoppingList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List shopping list summaries. Use get-shopping-list-tool for items. Results are ordered by id; follow next_cursor for more.')]
#[IsReadOnly]
class ListShoppingListsTool extends PaginatedHouseholdTool
{
    public function handle(Request $request): Response
    {
        return Response::json($this->page($request, $this->household()->shoppingLists()->withCount('items'),
            fn (ShoppingList $row) => ['id' => $row->id, 'name' => $row->name, 'dinner_plan_id' => $row->dinner_plan_id, 'item_count' => $row->items_count]));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }
}
