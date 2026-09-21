<?php

namespace App\Mcp\Tools;

use App\Actions\ShoppingLists\GenerateShoppingListFromPlan;
use App\Data\ShoppingListData;
use App\Enums\ApiTokenScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Generate a shopping list from a dinner plan: ingredients are scaled by servings and combined.')]
#[IsIdempotent(false)]
class GenerateShoppingListTool extends HouseholdTool
{
    protected ApiTokenScope $requiredScope = ApiTokenScope::Write;

    public function handle(Request $request, GenerateShoppingListFromPlan $action): Response
    {
        $validated = $request->validate(['dinner_plan_id' => ['required', 'integer']]);
        $plan = $this->household()->dinnerPlans()->findOrFail($validated['dinner_plan_id']);

        return Response::json(ShoppingListData::fromList($action->handle($plan, $request->user()))->toArray());
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
        ];
    }
}
