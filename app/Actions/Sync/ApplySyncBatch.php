<?php

namespace App\Actions\Sync;

use App\Models\Dinner;
use App\Models\DinnerItem;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies one batched delta sync for a household and returns the changes the
 * client should pull back.
 *
 * Push (incoming) and pull (outgoing) happen in one transaction:
 *
 *  1. Incoming rows are upserted in dependency order (parents before children)
 *     so a child's foreign keys — sent as parent UUIDs — resolve against the
 *     same batch. Conflicts use last-write-wins by the client `updated_at`;
 *     rows carrying a `deleted_at` become tombstones (the row is kept).
 *  2. Outgoing collects every row in the household changed since the client's
 *     cursor (`synced_at > last_sync`), tombstones included, so peers converge.
 *
 * Identity is the client `uuid`; the integer PK stays internal. The household
 * is always taken from the authenticated active household — never the client —
 * so rows cannot be written into someone else's household.
 */
class ApplySyncBatch
{
    /**
     * Resource definitions in dependency order. Each child's `fks` map a
     * foreign-key column to the parent resource key whose UUID→id map resolves
     * it. `nullableFks` may legitimately arrive as null.
     *
     * @return array<string, array{
     *     model: class-string<Model>,
     *     fields: list<string>,
     *     fks: array<string, string>,
     *     nullableFks: list<string>,
     *     hasHousehold: bool,
     *     with: list<string>,
     *     query: callable(Household): Builder,
     *     serialize: callable(Model): array<string, mixed>,
     * }>
     */
    private function resources(): array
    {
        return [
            'ingredients' => [
                'model' => Ingredient::class,
                'fields' => ['name', 'default_unit', 'category'],
                'fks' => [],
                'nullableFks' => [],
                'hasHousehold' => true,
                'with' => [],
                'query' => fn (Household $h): Builder => $h->ingredients()->getQuery(),
                'serialize' => fn (Ingredient $m): array => [
                    'id' => $m->uuid,
                    'name' => $m->name,
                    'default_unit' => $m->default_unit,
                    'category' => $m->category,
                ],
            ],
            'dinners' => [
                'model' => Dinner::class,
                'fields' => ['name', 'default_servings', 'notes'],
                'fks' => [],
                'nullableFks' => [],
                'hasHousehold' => true,
                'with' => [],
                'query' => fn (Household $h): Builder => $h->dinners()->getQuery(),
                'serialize' => fn (Dinner $m): array => [
                    'id' => $m->uuid,
                    'name' => $m->name,
                    'default_servings' => $m->default_servings,
                    'notes' => $m->notes,
                ],
            ],
            'dinner_items' => [
                'model' => DinnerItem::class,
                'fields' => ['quantity', 'unit'],
                'fks' => ['dinner_id' => 'dinners', 'ingredient_id' => 'ingredients'],
                'nullableFks' => [],
                'hasHousehold' => false,
                'with' => ['dinner:id,uuid', 'ingredient:id,uuid'],
                'query' => fn (Household $h): Builder => DinnerItem::query()
                    ->whereHas('dinner', fn (Builder $q) => $q->where('household_id', $h->id)),
                'serialize' => fn (DinnerItem $m): array => [
                    'id' => $m->uuid,
                    'dinner_id' => $m->dinner?->uuid,
                    'ingredient_id' => $m->ingredient?->uuid,
                    'quantity' => $m->quantity !== null ? (float) $m->quantity : null,
                    'unit' => $m->unit,
                ],
            ],
            'dinner_plans' => [
                'model' => DinnerPlan::class,
                'fields' => ['name', 'start_date', 'end_date'],
                'fks' => [],
                'nullableFks' => [],
                'hasHousehold' => true,
                'with' => [],
                'query' => fn (Household $h): Builder => $h->dinnerPlans()->getQuery(),
                'serialize' => fn (DinnerPlan $m): array => [
                    'id' => $m->uuid,
                    'name' => $m->name,
                    'start_date' => $m->start_date?->toDateString(),
                    'end_date' => $m->end_date?->toDateString(),
                ],
            ],
            'plan_entries' => [
                'model' => DinnerPlanEntry::class,
                'fields' => ['scheduled_date', 'servings', 'meal_type', 'notes'],
                'fks' => ['dinner_plan_id' => 'dinner_plans', 'dinner_id' => 'dinners'],
                'nullableFks' => [],
                'hasHousehold' => false,
                'with' => ['dinnerPlan:id,uuid', 'dinner:id,uuid'],
                'query' => fn (Household $h): Builder => DinnerPlanEntry::query()
                    ->whereHas('dinnerPlan', fn (Builder $q) => $q->where('household_id', $h->id)),
                'serialize' => fn (DinnerPlanEntry $m): array => [
                    'id' => $m->uuid,
                    'dinner_plan_id' => $m->dinnerPlan?->uuid,
                    'dinner_id' => $m->dinner?->uuid,
                    'scheduled_date' => $m->scheduled_date?->toDateString(),
                    'servings' => $m->servings,
                    'meal_type' => $m->meal_type->value,
                    'notes' => $m->notes,
                ],
            ],
            'shopping_lists' => [
                'model' => ShoppingList::class,
                'fields' => ['name'],
                'fks' => ['dinner_plan_id' => 'dinner_plans'],
                'nullableFks' => ['dinner_plan_id'],
                'hasHousehold' => true,
                'with' => ['dinnerPlan:id,uuid'],
                'query' => fn (Household $h): Builder => $h->shoppingLists()->getQuery(),
                'serialize' => fn (ShoppingList $m): array => [
                    'id' => $m->uuid,
                    'dinner_plan_id' => $m->dinnerPlan?->uuid,
                    'name' => $m->name,
                ],
            ],
            'shopping_list_items' => [
                'model' => ShoppingListItem::class,
                'fields' => ['name', 'quantity', 'unit', 'is_checked'],
                'fks' => ['shopping_list_id' => 'shopping_lists', 'ingredient_id' => 'ingredients'],
                'nullableFks' => ['ingredient_id'],
                'hasHousehold' => false,
                'with' => ['shoppingList:id,uuid', 'ingredient:id,uuid'],
                'query' => fn (Household $h): Builder => ShoppingListItem::query()
                    ->whereHas('shoppingList', fn (Builder $q) => $q->where('household_id', $h->id)),
                'serialize' => fn (ShoppingListItem $m): array => [
                    'id' => $m->uuid,
                    'shopping_list_id' => $m->shoppingList?->uuid,
                    'ingredient_id' => $m->ingredient?->uuid,
                    'name' => $m->name,
                    'quantity' => $m->quantity !== null ? (float) $m->quantity : null,
                    'unit' => $m->unit,
                    'is_checked' => (bool) $m->is_checked,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $changes
     * @return array{server_time: string, changes: array<string, array<int, array<string, mixed>>>}
     */
    public function handle(Household $household, ?string $lastSync, array $changes): array
    {
        $resources = $this->resources();

        return DB::transaction(function () use ($household, $lastSync, $changes, $resources): array {
            $syncedAt = CarbonImmutable::now();

            // uuid → internal id, seeded with the household's parent rows so
            // children created in earlier syncs still resolve.
            $maps = [
                'ingredients' => $household->ingredients()->pluck('id', 'uuid')->all(),
                'dinners' => $household->dinners()->pluck('id', 'uuid')->all(),
                'dinner_plans' => $household->dinnerPlans()->pluck('id', 'uuid')->all(),
                'shopping_lists' => $household->shoppingLists()->pluck('id', 'uuid')->all(),
            ];

            foreach ($resources as $key => $resource) {
                foreach ($changes[$key] ?? [] as $row) {
                    $this->applyRow($household, $key, $resource, $row, $maps, $syncedAt);
                }
            }

            $since = $lastSync !== null ? CarbonImmutable::parse($lastSync) : null;
            $outgoing = [];
            foreach ($resources as $key => $resource) {
                $outgoing[$key] = $this->collectOutgoing($household, $resource, $since);
            }

            return [
                'server_time' => $syncedAt->toISOString(),
                'changes' => $outgoing,
            ];
        });
    }

    /**
     * Upsert a single incoming row, applying last-write-wins and tombstones.
     *
     * @param  array{model: class-string<Model>, fields: list<string>, fks: array<string, string>, nullableFks: list<string>, hasHousehold: bool, with: list<string>, query: callable, serialize: callable}  $resource
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, int>>  $maps
     */
    private function applyRow(Household $household, string $key, array $resource, array $row, array &$maps, CarbonImmutable $syncedAt): void
    {
        $uuid = $row['id'] ?? null;
        if (! is_string($uuid) || $uuid === '') {
            throw ValidationException::withMessages(["changes.{$key}" => 'Each row requires a string id.']);
        }

        $foreignKeys = $this->resolveForeignKeys($key, $resource, $row, $maps);

        $incomingUpdatedAt = isset($row['updated_at'])
            ? CarbonImmutable::parse($row['updated_at'])
            : $syncedAt;

        /** @var Model|null $model */
        $model = $resource['model']::query()->where('uuid', $uuid)->first();

        if ($model !== null) {
            if ($resource['hasHousehold'] && $model->getAttribute('household_id') !== $household->id) {
                // Another household already owns this uuid — never overwrite it.
                $maps[$key][$uuid] = $model->getKey();

                return;
            }

            if ($model->updated_at !== null && $incomingUpdatedAt->lessThan($model->updated_at)) {
                // Server copy is newer — keep it, but still resolvable as a parent.
                $maps[$key][$uuid] = $model->getKey();

                return;
            }
        } else {
            $model = new $resource['model'];
            $model->setAttribute('uuid', $uuid);
        }

        $attributes = $foreignKeys;
        foreach ($resource['fields'] as $field) {
            if (array_key_exists($field, $row)) {
                $attributes[$field] = $row[$field];
            }
        }
        if ($resource['hasHousehold']) {
            $attributes['household_id'] = $household->id;
        }
        $attributes['created_at'] = isset($row['created_at']) ? CarbonImmutable::parse($row['created_at']) : $syncedAt;
        $attributes['updated_at'] = $incomingUpdatedAt;
        $attributes['deleted_at'] = ! empty($row['deleted_at']) ? CarbonImmutable::parse($row['deleted_at']) : null;
        $attributes['synced_at'] = $syncedAt;

        Model::withoutTimestamps(function () use ($model, $attributes): void {
            $model->forceFill($attributes)->save();
        });

        $maps[$key][$uuid] = $model->getKey();
    }

    /**
     * Resolve a row's parent-UUID foreign keys to internal ids.
     *
     * @param  array{fks: array<string, string>, nullableFks: list<string>}  $resource
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, int>>  $maps
     * @return array<string, int|null>
     */
    private function resolveForeignKeys(string $key, array $resource, array $row, array $maps): array
    {
        $resolved = [];
        foreach ($resource['fks'] as $column => $parentKey) {
            $parentUuid = $row[$column] ?? null;

            if ($parentUuid === null || $parentUuid === '') {
                if (in_array($column, $resource['nullableFks'], true)) {
                    $resolved[$column] = null;

                    continue;
                }
                throw ValidationException::withMessages(["changes.{$key}" => "Missing {$column}."]);
            }

            $parentId = $maps[$parentKey][$parentUuid] ?? null;
            if ($parentId === null) {
                throw ValidationException::withMessages([
                    "changes.{$key}" => "Unknown {$column} {$parentUuid}.",
                ]);
            }
            $resolved[$column] = $parentId;
        }

        return $resolved;
    }

    /**
     * Collect the household's rows changed since the client's cursor.
     *
     * @param  array{with: list<string>, query: callable, serialize: callable}  $resource
     * @return array<int, array<string, mixed>>
     */
    private function collectOutgoing(Household $household, array $resource, ?CarbonImmutable $since): array
    {
        $query = ($resource['query'])($household)->with($resource['with']);

        if ($since === null) {
            $query->whereNull('deleted_at');
        } else {
            // Compare at microsecond precision (matches the synced_at cast), so
            // the cursor never re-pulls or skips rows from the same second.
            $query->where('synced_at', '>', $since->format('Y-m-d H:i:s.u'));
        }

        return $query->get()
            ->map(fn (Model $model): array => $this->serializeRow($resource, $model))
            ->all();
    }

    /**
     * @param  array{serialize: callable}  $resource
     * @return array<string, mixed>
     */
    private function serializeRow(array $resource, Model $model): array
    {
        return array_merge(($resource['serialize'])($model), [
            'created_at' => $model->created_at?->toISOString(),
            'updated_at' => $model->updated_at?->toISOString(),
            'deleted_at' => $model->getAttribute('deleted_at')?->toISOString(),
        ]);
    }
}
