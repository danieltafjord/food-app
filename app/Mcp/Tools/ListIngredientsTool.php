<?php

namespace App\Mcp\Tools;

use App\Data\IngredientData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the ingredients in the household catalogue, with their ids, default units and categories.')]
#[IsReadOnly]
class ListIngredientsTool extends HouseholdTool
{
    public function handle(Request $request): Response
    {
        $ingredients = $this->household()->ingredients()->orderBy('name')->get();

        return Response::json(IngredientData::collect($ingredients)->map->toArray()->all());
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
