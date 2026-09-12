<?php

namespace App\Actions\Sync;

use App\Enums\MealType;
use App\Models\Concerns\Syncable;
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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies one batched delta sync for a household and returns what the client
 * should pull back.
 *
 * Push (incoming) and pull (outgoing) run in one transaction. A batch that
 * carries rows takes the household's row lock up front (see
 * AllocateSyncVersion) so write batches for the same household serialise; a
 * pure pull takes no lock and allocates no version — it reads the committed
 * `households.sync_version`, which is only ever advanced by a write in the
 * same transaction as the rows it stamps, so the integer cursor is a complete
 * delta either way. An empty poll whose cursor is already current is answered
 * without opening a transaction at all.
 *
 *  1. Every row is validated and applied on its own. A bad row is reported in
 *     `rejected` and skipped — it never fails the batch, so one poisoned row
 *     can't wedge a device's outbox. Rows are upserted in dependency order
 *     (parents before children) so a child's parent UUIDs resolve against the
 *     same batch. Conflicts use last-write-wins by the client `updated_at`
 *     (clamped to the server clock so a device with a future clock can't lock
 *     a row). Rows carrying `deleted_at` become tombstones: the row is kept,
 *     soft-deleted, and its children are tombstoned with it. A tombstone for a
 *     uuid the server never saw is a no-op.
 *  2. Outgoing collects every row in the household with a version above the
 *     client's cursor, tombstones included, plus any row the client pushed but
 *     lost on (so it converges on the server copy). A first sync (no cursor)
 *     gets only live rows whose parents are live.
 *
 * Identity is the client `uuid`; the integer PK stays internal. Ownership is
 * always the authenticated active household — never the client — so a uuid
 * that belongs to another household is treated as unknown: neither written nor
 * exposed, and never usable as a parent.
 *
 * Two devices that create the same ingredient name offline are merged: the
 * incoming uuid maps onto the existing ingredient and the client is told to
 * rewrite its references via `remaps`.
 */
class ApplySyncBatch
{
    /** Hard cap on rows per batch; the client chunks larger pushes. */
    public const MAX_ROWS = 1000;

    public function __construct(private AllocateSyncVersion $allocateVersion) {}

    /**
     * Resource definitions in dependency order. Each child's `fks` map a
     * foreign-key column to the parent resource whose UUID→id map resolves it
     * (both ways: incoming uuids to ids, outgoing ids to uuids); the first FK
     * also decides ownership. `nullableFks` may arrive as null.
     *
     * @return array<string, array{
     *     model: class-string<Model>,
     *     fields: list<string>,
     *     fks: array<string, string>,
     *     nullableFks: list<string>,
     *     hasHousehold: bool,
     *     query: callable(Household, bool): Builder,
     *     serialize: callable(Model): array<string, mixed>,
     *     rules: array<string, list<mixed>>,
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
                'query' => fn (Household $h, bool $liveParents): Builder => $h->ingredients()->getQuery(),
                'serialize' => fn (Ingredient $m): array => [
                    'name' => $m->name,
                    'default_unit' => $m->default_unit,
                    'category' => $m->category,
                ],
                'rules' => [
                    'name' => ['required', 'string', 'max:255'],
                    'default_unit' => ['nullable', 'string', 'max:50'],
                    'category' => ['nullable', 'string', 'max:50'],
                ],
            ],
            'dinners' => [
                'model' => Dinner::class,
                'fields' => ['name', 'default_servings', 'notes'],
                'fks' => [],
                'nullableFks' => [],
                'hasHousehold' => true,
                'query' => fn (Household $h, bool $liveParents): Builder => $h->dinners()->getQuery(),
                'serialize' => fn (Dinner $m): array => [
                    'name' => $m->name,
                    'default_servings' => $m->default_servings,
                    'notes' => $m->notes,
                ],
                'rules' => [
                    'name' => ['required', 'string', 'max:255'],
                    'default_servings' => ['required', 'integer', 'min:1', 'max:99'],
                    'notes' => ['nullable', 'string', 'max:5000'],
                ],
            ],
            'dinner_items' => [
                'model' => DinnerItem::class,
                'fields' => ['quantity', 'unit'],
                'fks' => ['dinner_id' => 'dinners', 'ingredient_id' => 'ingredients'],
                'nullableFks' => [],
                'hasHousehold' => false,
                'query' => fn (Household $h, bool $liveParents): Builder => DinnerItem::query()
                    ->whereIn('dinner_id', fn (QueryBuilder $q) => $this->parentIds($q, 'dinners', $h, $liveParents)),
                'serialize' => fn (DinnerItem $m): array => [
                    'quantity' => $m->quantity !== null ? (float) $m->quantity : null,
                    'unit' => $m->unit,
                ],
                'rules' => [
                    'dinner_id' => ['required', 'uuid'],
                    'ingredient_id' => ['required', 'uuid'],
                    'quantity' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
                    'unit' => ['nullable', 'string', 'max:50'],
                ],
            ],
            'dinner_plans' => [
                'model' => DinnerPlan::class,
                'fields' => ['name', 'start_date', 'end_date'],
                'fks' => [],
                'nullableFks' => [],
                'hasHousehold' => true,
                'query' => fn (Household $h, bool $liveParents): Builder => $h->dinnerPlans()->getQuery(),
                'serialize' => fn (DinnerPlan $m): array => [
                    'name' => $m->name,
                    'start_date' => $m->start_date?->toDateString(),
                    'end_date' => $m->end_date?->toDateString(),
                ],
                'rules' => [
                    'name' => ['required', 'string', 'max:255'],
                    'start_date' => ['nullable', 'date_format:Y-m-d'],
                    'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                ],
            ],
            'plan_entries' => [
                'model' => DinnerPlanEntry::class,
                'fields' => ['scheduled_date', 'servings', 'meal_type', 'notes'],
                'fks' => ['dinner_plan_id' => 'dinner_plans', 'dinner_id' => 'dinners'],
                'nullableFks' => [],
                'hasHousehold' => false,
                'query' => fn (Household $h, bool $liveParents): Builder => DinnerPlanEntry::query()
                    ->whereIn('dinner_plan_id', fn (QueryBuilder $q) => $this->parentIds($q, 'dinner_plans', $h, $liveParents)),
                'serialize' => fn (DinnerPlanEntry $m): array => [
                    'scheduled_date' => $m->scheduled_date?->toDateString(),
                    'servings' => $m->servings,
                    'meal_type' => $m->meal_type->value,
                    'notes' => $m->notes,
                ],
                'rules' => [
                    'dinner_plan_id' => ['required', 'uuid'],
                    'dinner_id' => ['required', 'uuid'],
                    'scheduled_date' => ['required', 'date_format:Y-m-d'],
                    'servings' => ['required', 'integer', 'min:1', 'max:99'],
                    'meal_type' => ['required', Rule::enum(MealType::class)],
                    'notes' => ['nullable', 'string', 'max:5000'],
                ],
            ],
            'shopping_lists' => [
                'model' => ShoppingList::class,
                'fields' => ['name'],
                'fks' => ['dinner_plan_id' => 'dinner_plans'],
                'nullableFks' => ['dinner_plan_id'],
                'hasHousehold' => true,
                'query' => fn (Household $h, bool $liveParents): Builder => $h->shoppingLists()->getQuery(),
                'serialize' => fn (ShoppingList $m): array => [
                    'name' => $m->name,
                ],
                'rules' => [
                    'dinner_plan_id' => ['nullable', 'uuid'],
                    'name' => ['required', 'string', 'max:255'],
                ],
            ],
            'shopping_list_items' => [
                'model' => ShoppingListItem::class,
                'fields' => ['name', 'quantity', 'unit', 'is_checked'],
                'fks' => ['shopping_list_id' => 'shopping_lists', 'ingredient_id' => 'ingredients'],
                'nullableFks' => ['ingredient_id'],
                'hasHousehold' => false,
                'query' => fn (Household $h, bool $liveParents): Builder => ShoppingListItem::query()
                    ->whereIn('shopping_list_id', fn (QueryBuilder $q) => $this->parentIds($q, 'shopping_lists', $h, $liveParents)),
                'serialize' => fn (ShoppingListItem $m): array => [
                    'name' => $m->name,
                    'quantity' => $m->quantity !== null ? (float) $m->quantity : null,
                    'unit' => $m->unit,
                    'is_checked' => (bool) $m->is_checked,
                ],
                'rules' => [
                    'shopping_list_id' => ['required', 'uuid'],
                    'ingredient_id' => ['nullable', 'uuid'],
                    'name' => ['nullable', 'required_without:ingredient_id', 'string', 'max:255'],
                    'quantity' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
                    'unit' => ['nullable', 'string', 'max:50'],
                    'is_checked' => ['nullable', 'boolean'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $changes
     * @return array{
     *     cursor: int,
     *     household_id: int,
     *     changes: array<string, array<int, array<string, mixed>>>,
     *     rejected: array<string, array<int, array{id: ?string, code: string, message: string}>>,
     *     remaps: array<string, array<string, string>>,
     * }
     */
    public function handle(Household $household, ?int $cursor, array $changes): array
    {
        $resources = $this->resources();
        $this->assertBatchSize($changes);

        $incoming = [];
        foreach ($resources as $key => $resource) {
            $rows = $changes[$key] ?? [];
            if (is_array($rows) && $rows !== []) {
                $incoming[$key] = array_values($rows);
            }
        }

        // The routine poll: nothing to push and the device is already at the
        // household's version. Answer from the household row the middleware
        // loaded — no transaction, no lock, no table scans.
        $current = (int) $household->sync_version;
        if ($incoming === [] && $cursor !== null && $cursor >= $current) {
            return [
                'cursor' => $current,
                'household_id' => $household->id,
                'changes' => array_fill_keys(array_keys($resources), []),
                'rejected' => [],
                'remaps' => [],
            ];
        }

        return DB::transaction(function () use ($household, $cursor, $incoming, $resources): array {
            $now = CarbonImmutable::now();
            // A write batch locks the household for the rest of the transaction
            // so peers' write batches wait; a pull just reads the committed version.
            $committed = $this->committedVersion($household, lock: $incoming !== []);
            $state = $this->newState($household, $resources);

            foreach ($incoming as $key => $rows) {
                $existing = $this->prefetch($resources[$key], $rows);
                foreach ($rows as $row) {
                    $this->applyRow($household, $key, $resources[$key], $row, $state, $existing, $now);
                }
            }

            $outgoing = [];
            foreach ($resources as $key => $resource) {
                $outgoing[$key] = $this->collectOutgoing($household, $key, $resource, $cursor, $state);
            }

            return [
                'cursor' => $state->allocatedVersion() ?? $committed,
                'household_id' => $household->id,
                'changes' => $outgoing,
                'rejected' => $state->rejected,
                'remaps' => $state->remaps,
            ];
        });
    }

    private function committedVersion(Household $household, bool $lock): int
    {
        $query = Household::query()->whereKey($household->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return (int) $query->value('sync_version');
    }

    /**
     * @param  array<string, array{model: class-string<Model>}>  $resources
     */
    private function newState(Household $household, array $resources): SyncBatchState
    {
        return new SyncBatchState(
            loadMap: fn (string $key): array => $resources[$key]['model']::withTrashed()
                ->where('household_id', $household->id)
                ->pluck('id', 'uuid')
                ->all(),
            loadUuids: fn (string $key, array $ids): array => $resources[$key]['model']::withTrashed()
                ->whereKey($ids)
                ->pluck('uuid', 'id')
                ->all(),
            loadIngredientNames: function () use ($household): array {
                $byName = [];
                foreach ($household->ingredients()->orderBy('id')->get(['id', 'uuid', 'name']) as $ingredient) {
                    $byName[SyncBatchState::nameKey($ingredient->name)] ??= ['id' => $ingredient->id, 'uuid' => $ingredient->uuid];
                }

                return $byName;
            },
            allocateVersion: fn (): int => $this->allocateVersion->handle($household->id),
        );
    }

    /**
     * Load every existing row an incoming batch refers to in one query, keyed
     * by uuid (any household — ownership is checked per row).
     *
     * @param  array{model: class-string<Model>}  $resource
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, Model&Syncable>
     */
    private function prefetch(array $resource, array $rows): array
    {
        $uuids = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $uuids[$row['id']] = true;
            }
        }
        if ($uuids === []) {
            return [];
        }

        return $resource['model']::withTrashed()->whereIn('uuid', array_keys($uuids))->get()->keyBy('uuid')->all();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function assertBatchSize(array $changes): void
    {
        $rows = array_sum(array_map(fn ($list) => is_array($list) ? count($list) : 0, $changes));

        if ($rows > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'changes' => 'A sync batch may contain at most '.self::MAX_ROWS.' rows.',
            ]);
        }
    }

    /**
     * Validate and upsert (or tombstone) a single incoming row.
     *
     * @param  array{model: class-string<Model>, fields: list<string>, fks: array<string, string>, nullableFks: list<string>, hasHousehold: bool, rules: array<string, list<mixed>>}  $resource
     * @param  array<string, mixed>  $row
     * @param  array<string, Model&Syncable>  $existing  prefetched rows by uuid; rows written here are added
     */
    private function applyRow(Household $household, string $key, array $resource, array $row, SyncBatchState $state, array &$existing, CarbonImmutable $now): void
    {
        $uuid = $row['id'] ?? null;
        $isTombstone = ! empty($row['deleted_at']);

        $validator = Validator::make($row, $isTombstone ? $this->baseRules() : $this->baseRules() + $resource['rules']);
        if ($validator->fails()) {
            $state->reject($key, is_string($uuid) ? $uuid : null, 'invalid', $validator->errors()->first());

            return;
        }

        try {
            $incomingUpdatedAt = $this->clientTime($row['updated_at'] ?? null, $now);
            $incomingDeletedAt = $isTombstone ? $this->clientTime($row['deleted_at'], $now) : null;
            $incomingCreatedAt = $this->clientTime($row['created_at'] ?? null, $now);
        } catch (Throwable) {
            $state->reject($key, $uuid, 'invalid', 'Timestamps must be ISO-8601.');

            return;
        }

        $model = $existing[$uuid] ?? null;

        if ($model !== null && ! $this->isOwned($model, $resource, $household, $state)) {
            // Another household's row (or a uuid collision): behave as if it did not exist.
            if (! $isTombstone) {
                $state->reject($key, $uuid, 'unknown_id', 'That id belongs to another household.');
            }

            return;
        }

        if ($isTombstone) {
            if ($model === null) {
                return; // Deleted before it ever reached the server.
            }
            $state->remember($key, $uuid, $model->getKey());
            if ($this->serverIsNewer($model, $incomingUpdatedAt)) {
                $state->include($key, $uuid);

                return;
            }
            $model->tombstone($incomingDeletedAt, $state->version());
            if ($key === 'ingredients') {
                $state->forgetIngredientName($model->name);
            }

            return;
        }

        if ($model !== null) {
            $state->remember($key, $uuid, $model->getKey());
            if ($this->serverIsNewer($model, $incomingUpdatedAt)) {
                // Keep the server copy and send it back so the client converges.
                $state->include($key, $uuid);

                return;
            }
        } elseif ($key === 'ingredients' && ($sameNamed = $state->ingredientNamed($row['name'])) !== null) {
            // Two devices created the same ingredient offline: merge onto the first.
            $state->alias($key, $uuid, $sameNamed['id']);
            $state->remaps[$key][$uuid] = $sameNamed['uuid'];
            $state->include($key, $sameNamed['uuid']);

            return;
        } else {
            $model = new $resource['model'];
            $model->setAttribute('uuid', $uuid);
        }
        $previousName = $key === 'ingredients' && $model->exists ? $model->getOriginal('name') : null;

        $foreignKeys = $this->resolveForeignKeys($resource, $row, $state);
        if (is_string($foreignKeys)) {
            $state->reject($key, $uuid, 'unknown_parent', $foreignKeys);

            return;
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
        $attributes['created_at'] = $model->exists ? $model->created_at : $incomingCreatedAt;
        $attributes['updated_at'] = $incomingUpdatedAt;
        $attributes['deleted_at'] = null; // A newer live version restores a tombstone.

        $model->forceFill($attributes)->stampSync($state->version(), $now);
        Model::withoutTimestamps(fn () => $model->save());

        $existing[$uuid] = $model;
        $state->remember($key, $uuid, $model->getKey());
        if ($key === 'ingredients') {
            if ($previousName !== null && SyncBatchState::nameKey($previousName) !== SyncBatchState::nameKey($model->name)) {
                $state->forgetIngredientName($previousName);
            }
            $state->rememberIngredientName($model->name, $model->getKey(), $model->uuid);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function baseRules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'created_at' => ['nullable', 'date'],
            'updated_at' => ['nullable', 'date'],
            'deleted_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Parse a client timestamp as UTC, never later than the server clock.
     */
    private function clientTime(mixed $value, CarbonImmutable $now): CarbonImmutable
    {
        if ($value === null || $value === '') {
            return $now;
        }
        $parsed = CarbonImmutable::parse($value)->utc();

        return $parsed->greaterThan($now) ? $now : $parsed;
    }

    private function serverIsNewer(Model $model, CarbonImmutable $incomingUpdatedAt): bool
    {
        return $model->updated_at !== null && $incomingUpdatedAt->lessThan($model->updated_at);
    }

    /**
     * Whether an existing row belongs to the active household — directly, or
     * through the parent that owns it.
     *
     * @param  array{fks: array<string, string>, hasHousehold: bool}  $resource
     */
    private function isOwned(Model $model, array $resource, Household $household, SyncBatchState $state): bool
    {
        if ($resource['hasHousehold']) {
            return (int) $model->getAttribute('household_id') === $household->id;
        }

        $column = array_key_first($resource['fks']);
        $parentKey = $resource['fks'][$column];

        return $state->owns($parentKey, (int) $model->getAttribute($column));
    }

    /**
     * Resolve a row's parent-UUID foreign keys to internal ids, or return the
     * reason they could not be resolved.
     *
     * @param  array{fks: array<string, string>, nullableFks: list<string>}  $resource
     * @param  array<string, mixed>  $row
     * @return array<string, int|null>|string
     */
    private function resolveForeignKeys(array $resource, array $row, SyncBatchState $state): array|string
    {
        $resolved = [];
        foreach ($resource['fks'] as $column => $parentKey) {
            $parentUuid = $row[$column] ?? null;

            if ($parentUuid === null || $parentUuid === '') {
                if (in_array($column, $resource['nullableFks'], true)) {
                    $resolved[$column] = null;

                    continue;
                }

                return "Missing {$column}.";
            }

            $parentId = is_string($parentUuid) ? $state->id($parentKey, $parentUuid) : null;
            if ($parentId === null) {
                return "Unknown {$column} {$parentUuid}.";
            }
            $resolved[$column] = $parentId;
        }

        return $resolved;
    }

    /**
     * The household's parent ids for a child pull, as a subselect on the
     * parent's `(household_id, sync_version)` index — live parents only for a
     * first sync, so a fresh client never receives children of a tombstone.
     */
    private function parentIds(QueryBuilder $query, string $table, Household $household, bool $liveParents): void
    {
        $query->select('id')->from($table)->where('household_id', $household->id);
        if ($liveParents) {
            $query->whereNull('deleted_at');
        }
    }

    /**
     * Collect the household's rows above the client's cursor (tombstones
     * included), plus any explicitly included uuids.
     *
     * @param  array{fks: array<string, string>, query: callable, serialize: callable}  $resource
     * @return array<int, array<string, mixed>>
     */
    private function collectOutgoing(Household $household, string $key, array $resource, ?int $cursor, SyncBatchState $state): array
    {
        $include = $state->include[$key] ?? [];
        $query = ($resource['query'])($household, $cursor === null)->withTrashed();

        if ($cursor === null) {
            $query->where(fn (Builder $q) => $q->whereNull('deleted_at')->orWhereIn('uuid', $include));
        } else {
            $query->where(fn (Builder $q) => $q->where('sync_version', '>', $cursor)->orWhereIn('uuid', $include));
        }

        $models = $query->orderBy('id')->get();
        if ($models->isEmpty()) {
            return [];
        }

        // Parent uuids come from the maps already loaded for the push, or one
        // lookup of exactly the parent ids these rows reference.
        foreach ($resource['fks'] as $column => $parentKey) {
            $state->resolveUuids($parentKey, $models->pluck($column)->all());
        }

        return $models
            ->map(fn (Model $model): array => $this->serializeRow($resource, $model, $state))
            ->all();
    }

    /**
     * @param  array{fks: array<string, string>, serialize: callable}  $resource
     * @return array<string, mixed>
     */
    private function serializeRow(array $resource, Model $model, SyncBatchState $state): array
    {
        $foreignKeys = [];
        foreach ($resource['fks'] as $column => $parentKey) {
            $foreignKeys[$column] = $state->uuidOf($parentKey, $model->getAttribute($column));
        }

        return array_merge(['id' => $model->getAttribute('uuid')], $foreignKeys, ($resource['serialize'])($model), [
            'created_at' => $model->created_at?->toISOString(),
            'updated_at' => $model->updated_at?->toISOString(),
            'deleted_at' => $model->getAttribute('deleted_at')?->toISOString(),
        ]);
    }
}
