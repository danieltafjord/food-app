<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddShoppingListItemTool;
use App\Mcp\Tools\CheckShoppingListItemTool;
use App\Mcp\Tools\CreateShoppingListTool;
use App\Mcp\Tools\GenerateShoppingListTool;
use App\Mcp\Tools\GetDinnerPlanTool;
use App\Mcp\Tools\GetDinnerTool;
use App\Mcp\Tools\GetShoppingListTool;
use App\Mcp\Tools\GetTodayTool;
use App\Mcp\Tools\ListDinnerPlansTool;
use App\Mcp\Tools\ListDinnersTool;
use App\Mcp\Tools\ListIngredientsTool;
use App\Mcp\Tools\ListShoppingListsTool;
use App\Mcp\Tools\PlanDinnerTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Handlelista')]
#[Version('2.0.0')]
#[Instructions('Manage a household\'s meal planning: look up what is planned for today, browse dinners (recipes), ingredients, dinner plans and shopping lists, add or check off shopping list items, schedule dinners and generate a shopping list from a plan. All data belongs to the single household the API token is pinned to. Look up ids with the list tools before calling a tool that needs one. List tools return summaries in data and a next_cursor; pass it as after_id for more. Use get-dinner-tool, get-dinner-plan-tool and get-shopping-list-tool for paginated contents.')]
class HandlelistaServer extends Server
{
    protected array $tools = [
        GetTodayTool::class,
        GetDinnerTool::class,
        GetDinnerPlanTool::class,
        GetShoppingListTool::class,
        ListShoppingListsTool::class,
        ListDinnersTool::class,
        ListDinnerPlansTool::class,
        ListIngredientsTool::class,
        CreateShoppingListTool::class,
        AddShoppingListItemTool::class,
        CheckShoppingListItemTool::class,
        PlanDinnerTool::class,
        GenerateShoppingListTool::class,
    ];
}
