<?php

namespace App\Mcp\Tools;

use App\Data\IngredientData;
use App\Models\Ingredient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Search the household ingredient catalogue, including ids, default units and categories. Results are ordered by id; follow next_cursor for more.')]
#[IsReadOnly]
class ListIngredientsTool extends PaginatedHouseholdTool
{
    public function handle(Request $request): Response
    {
        return Response::json($this->page($request, $this->household()->ingredients(),
            fn (Ingredient $row) => IngredientData::from($row)->toArray()));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }
}
