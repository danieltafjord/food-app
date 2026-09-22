<?php

namespace App\Mcp\Tools;

use App\Data\DinnerItemData;
use App\Models\DinnerItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get one household record and a page of its items. Follow next_cursor with the same dinner_id to read more.')]
#[IsReadOnly]
class GetDinnerTool extends PaginatedHouseholdTool
{
    public function handle(Request $request): Response
    {
        $input = $request->validate(['dinner_id' => ['required', 'integer']]);
        $record = $this->household()->dinners()->findOrFail($input['dinner_id']);
        $page = $this->page($request, $record->items()->with('ingredient'),
            fn (DinnerItem $row) => DinnerItemData::fromItem($row)->toArray(), searchable: false);

        return Response::json(['record' => ['id' => $record->id, 'name' => $record->name, 'default_servings' => $record->default_servings, 'notes' => $record->notes], ...$page]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['dinner_id' => $schema->integer()->required(), ...$this->pageSchema($schema, searchable: false)];
    }
}
