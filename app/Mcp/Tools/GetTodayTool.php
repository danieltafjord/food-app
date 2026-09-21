<?php

namespace App\Mcp\Tools;

use App\Actions\DinnerPlans\ListEntriesForDate;
use App\Data\DinnerPlanEntryData;
use App\Models\DinnerPlanEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('What the household has planned to eat today (or on another date).')]
#[IsReadOnly]
class GetTodayTool extends HouseholdTool
{
    public function handle(Request $request, ListEntriesForDate $action): Response
    {
        $validated = $request->validate(['date' => ['nullable', 'date']]);

        $entries = $action->handle($this->household(), Date::parse($validated['date'] ?? today()))
            ->map(fn (DinnerPlanEntry $entry) => DinnerPlanEntryData::fromEntry($entry)->toArray());

        return Response::json($entries->all());
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->format('date')->description('Day to look up as YYYY-MM-DD. Defaults to today.'),
        ];
    }
}
