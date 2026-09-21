<?php

namespace App\Mcp\Tools;

use App\Actions\DinnerPlans\AddPlanEntry;
use App\Data\DinnerPlanEntryData;
use App\Data\DinnerPlanEntryInputData;
use App\Enums\ApiTokenScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Schedule a dinner on a date within a dinner plan.')]
#[IsIdempotent(false)]
class PlanDinnerTool extends HouseholdTool
{
    protected ApiTokenScope $requiredScope = ApiTokenScope::Write;

    public function handle(Request $request, AddPlanEntry $action): Response
    {
        $validated = $request->validate(['dinner_plan_id' => ['required', 'integer']]);
        $plan = $this->household()->dinnerPlans()->findOrFail($validated['dinner_plan_id']);

        $entry = $action->handle($plan, DinnerPlanEntryInputData::validateAndCreate(Arr::except($request->all(), 'dinner_plan_id')));

        return Response::json(DinnerPlanEntryData::fromEntry($entry)->toArray());
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'dinner_plan_id' => $schema->integer()->description('Id of the dinner plan.')->required(),
            'dinner_id' => $schema->integer()->description('Id of the dinner to schedule.')->required(),
            'scheduled_date' => $schema->string()->format('date')->description('Date as YYYY-MM-DD, within the plan\'s range.')->required(),
            'servings' => $schema->integer()->min(1)->max(99)->description('Number of servings to cook.')->required(),
            'meal_type' => $schema->string()->enum(['breakfast', 'lunch', 'dinner'])->description('Defaults to dinner.'),
            'notes' => $schema->string()->description('Optional note for the entry.'),
        ];
    }
}
