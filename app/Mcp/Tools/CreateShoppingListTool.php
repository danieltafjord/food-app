<?php

namespace App\Mcp\Tools;

use App\Actions\ShoppingLists\CreateShoppingList;
use App\Data\ShoppingListData;
use App\Data\ShoppingListInputData;
use App\Enums\ApiTokenScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Create a new, empty shopping list.')]
#[IsIdempotent(false)]
class CreateShoppingListTool extends HouseholdTool
{
    protected ApiTokenScope $requiredScope = ApiTokenScope::Write;

    public function handle(Request $request, CreateShoppingList $action): Response
    {
        $list = $action->handle($this->household(), ShoppingListInputData::validateAndCreate($request->all()), $request->user());

        return Response::json(ShoppingListData::fromList($list)->toArray());
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Name of the list.')->required(),
        ];
    }
}
