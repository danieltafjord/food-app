<?php

namespace App\Mcp\Tools;

use App\Data\DinnerData;
use App\Models\Dinner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the household\'s dinners (recipes) with their ingredients and default servings.')]
#[IsReadOnly]
class ListDinnersTool extends HouseholdTool
{
    public function handle(Request $request): Response
    {
        $dinners = $this->household()->dinners()
            ->with('items.ingredient')
            ->orderBy('name')
            ->get()
            ->map(fn (Dinner $dinner) => DinnerData::fromDinner($dinner)->toArray());

        return Response::json($dinners->all());
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
