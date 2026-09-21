<?php

namespace App\Mcp\Tools;

use App\Data\DinnerPlanData;
use App\Models\DinnerPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the household\'s dinner plans with their scheduled entries, newest first.')]
#[IsReadOnly]
class ListDinnerPlansTool extends HouseholdTool
{
    public function handle(Request $request): Response
    {
        $plans = $this->household()->dinnerPlans()
            ->with('entries.dinner')
            ->latest('start_date')
            ->get()
            ->map(fn (DinnerPlan $plan) => DinnerPlanData::fromPlan($plan)->toArray());

        return Response::json($plans->all());
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
