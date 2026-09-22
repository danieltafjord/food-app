<?php

namespace App\Mcp\Tools;

use App\Models\Dinner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List dinner summaries. Use get-dinner-tool for ingredients and notes. Results are ordered by id; follow next_cursor for more.')]
#[IsReadOnly]
class ListDinnersTool extends PaginatedHouseholdTool
{
    public function handle(Request $request): Response
    {
        return Response::json($this->page($request, $this->household()->dinners()->withCount('items'),
            fn (Dinner $row) => ['id' => $row->id, 'name' => $row->name, 'default_servings' => $row->default_servings, 'item_count' => $row->items_count]));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }
}
