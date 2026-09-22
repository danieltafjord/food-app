<?php

namespace App\Mcp\Tools;

use App\Data\DinnerPlanEntryData;
use App\Models\DinnerPlanEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get one household record and a page of its entries. Follow next_cursor with the same dinner_plan_id to read more.')]
#[IsReadOnly]
class GetDinnerPlanTool extends PaginatedHouseholdTool
{
    public function handle(Request $request): Response
    {
        $input = $request->validate(['dinner_plan_id' => ['required', 'integer']]);
        $record = $this->household()->dinnerPlans()->findOrFail($input['dinner_plan_id']);
        $page = $this->page($request, $record->entries()->with('dinner'),
            fn (DinnerPlanEntry $row) => DinnerPlanEntryData::fromEntry($row)->toArray(), searchable: false);

        return Response::json(['record' => ['id' => $record->id, 'name' => $record->name, 'start_date' => $record->start_date?->toDateString(), 'end_date' => $record->end_date?->toDateString()], ...$page]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['dinner_plan_id' => $schema->integer()->required(), ...$this->pageSchema($schema, searchable: false)];
    }
}
