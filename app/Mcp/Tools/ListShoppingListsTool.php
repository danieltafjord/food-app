<?php

namespace App\Mcp\Tools;

use App\Data\ShoppingListData;
use App\Models\ShoppingList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the household\'s shopping lists with their items, newest first.')]
#[IsReadOnly]
class ListShoppingListsTool extends HouseholdTool
{
    public function handle(Request $request): Response
    {
        $lists = $this->household()->shoppingLists()
            ->with('items.ingredient')
            ->latest()
            ->get()
            ->map(fn (ShoppingList $list) => ShoppingListData::fromList($list)->toArray());

        return Response::json($lists->all());
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [

        ];
    }
}
