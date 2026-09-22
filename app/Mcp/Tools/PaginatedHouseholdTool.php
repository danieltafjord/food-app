<?php

namespace App\Mcp\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;

abstract class PaginatedHouseholdTool extends HouseholdTool
{
    /** @return array<string, Type> */
    protected function pageSchema(JsonSchema $schema, bool $searchable = true): array
    {
        $fields = [
            'limit' => $schema->integer()->min(1)->max(50)->description('Page size, default 20, maximum 50.'),
            'after_id' => $schema->integer()->min(0)->description('Pass the previous response next_cursor to fetch the next page.'),
        ];
        if ($searchable) {
            $fields['search'] = $schema->string()->max(120)->description('Filter names by this text.');
        }

        return $fields;
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?int} */
    protected function page(Request $request, Builder|Relation $query, Closure $serialize, bool $searchable = true): array
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'after_id' => ['sometimes', 'integer', 'min:0'],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);
        $limit = $input['limit'] ?? 20;
        $id = $query->getModel()->qualifyColumn('id');
        if ($searchable && filled($input['search'] ?? null)) {
            $query->whereLike($query->getModel()->qualifyColumn('name'), '%'.$input['search'].'%');
        }
        $rows = $query->where($id, '>', $input['after_id'] ?? 0)->reorder($id)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'data' => $rows->map($serialize)->values()->all(),
            'next_cursor' => $hasMore ? (int) $rows->last()->getKey() : null,
        ];
    }
}
