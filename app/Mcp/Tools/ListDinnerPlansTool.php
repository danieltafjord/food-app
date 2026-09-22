<?php

namespace App\Mcp\Tools;

use App\Models\DinnerPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List dinner plan summaries. Use get-dinner-plan-tool for scheduled entries. Results are ordered by id; follow next_cursor for more.')]
#[IsReadOnly]
class ListDinnerPlansTool extends PaginatedHouseholdTool
{
    public function handle(Request $request): Response
    {
        return Response::json($this->page($request, $this->household()->dinnerPlans()->withCount('entries'),
            fn (DinnerPlan $row) => ['id' => $row->id, 'name' => $row->name, 'start_date' => $row->start_date?->toDateString(), 'end_date' => $row->end_date?->toDateString(), 'entry_count' => $row->entries_count]));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }
}
