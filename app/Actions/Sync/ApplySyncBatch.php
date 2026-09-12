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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies one batched delta sync for a household and returns what the client
 * should pull back.
 *
 * Push (incoming) and pull (outgoing) run in one transaction that holds the
 * household's version lock (see AllocateSyncVersion), so syncs for the same
 * household serialise and the integer cursor is a complete delta:
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
     * foreign-key column to the parent resource whose UUID→id map resolves it;
     * the first FK also decides ownership. `nullableFks` may arrive as null.
     *
     * @return array<string, array{
     *     model: class-string<Model>,
     *     fields: list<string>,
     *     fks: array<string, string>,
     *     nullableFks: list<string>,
     *     hasHousehold: bool,
     *     parent: ?string,
     *     with: array<string, callable>,
     *     query: callable(Household, bool): Builder,
     *     serialize: callable(Model): array<string, mixed>,
     *     rules: array<string, list<mixed>>,
     * }>
     */
    private function resources(): array
    {
        // Eager-load closures receive the relation, which proxies to the builder.
        $withTrashed = fn ($q) => $q->withTrashed()->select(['id', 'uuid']);

        return [
            'ingredients' => [
                'model' => Ingredient::class,
                'fields' => ['name', 'default_unit', 'category'],
                'fks' => [],
                'nullableFks' => [],
                'hasHousehold' => true,
                'parent' => null,
                'with' => [],
                'query' => fn (Household $h, bool $liveParents): Builder => $h->ingredients()->getQuery(),
                'serialize' => fn (Ingredient $m): array => [
                    'id' => $m->uuid,
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
                'parent' => null,
                'with' => [],
                'query' => fn (Household $h, bool $liveParents): Builder => $h->dinners()->getQuery(),
                'serialize' => fn (Dinner $m): array => [
                    'id' => $m->uuid,
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
                'parent' => 'dinner',
                'with' => ['dinner' => $withTrashed, 'ingredient' => $withTrashed],
                'query' => fn (Household $h, bool $liveParents): Builder => DinnerItem::query()
                    ->whereHas('dinner', fn (Builder $q) => $this->parentScope($q, $h, $liveParents)),
                'serialize' => fn (DinnerItem $m): array => [
                    'id' => $m->uuid,
                    'dinner_id' => $m->dinner?->uuid,
                    'ingredient_id' => $m->ingredient?->uuid,
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
                'parent' => null,
                'with' => [],
                'query' => fn (Household $h, bool $liveParents): Builder => $h->dinnerPlans()->getQuery(),
                'serialize' => fn (DinnerPlan $m): array => [
                    'id' => $m->uuid,
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
                'parent' => 'dinnerPlan',
                'with' => ['dinnerPlan' => $withTrashed, 'dinner' => $withTrashed],
                'query' => fn (Household $h, bool $liveParents): Builder => DinnerPlanEntry::query()
                    ->whereHas('dinnerPlan', fn (Builder $q) => $this->parentScope($q, $h, $liveParents)),
                'serialize' => fn (DinnerPlanEntry $m): array => [
                    'id' => $m->uuid,
                    'dinner_plan_id' => $m->dinnerPlan?->uuid,
                    'dinner_id' => $m->dinner?->uuid,
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
                'parent' => null,
                'with' => ['dinnerPlan' => $withTrashed],
                'query' => fn (Household $h, bool $liveParents): Builder => $h->shoppingLists()->getQuery(),
                'serialize' => fn (ShoppingList $m): array => [
                    'id' => $m->uuid,
                    'dinner_plan_id' => $m->dinnerPlan?->uuid,
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
                'parent' => 'shoppingList',
                'with' => ['shoppingList' => $withTrashed, 'ingredient' => $withTrashed],
                'query' => fn (Household $h, bool $liveParents): Builder => ShoppingListItem::query()
                    ->whereHas('shoppingList', fn (Builder $q) => $this->parentScope($q, $h, $liveParents)),
                'serialize' => fn (ShoppingListItem $m): array => [
                    'id' => $m->uuid,
                    'shopping_list_id' => $m->shoppingList?->uuid,
                    'ingredient_id' => $m->ingredient?->uuid,
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

        return DB::transaction(function () use ($household, $cursor, $changes, $resources): array {
            // Locks the household for the rest of the transaction: peers wait.
            $version = $this->allocateVersion->handle($household->id);
            $now = CarbonImmutable::now();

            $state = new SyncBatchState(
                maps: [
                    'ingredients' => $household->ingredients()->withTrashed()->pluck('id', 'uuid')->all(),
                    'dinners' => $household->dinners()->withTrashed()->pluck('id', 'uuid')->all(),
                    'dinner_plans' => $household->dinnerPlans()->withTrashed()->pluck('id', 'uuid')->all(),
                    'shopping_lists' => $household->shoppingLists()->withTrashed()->pluck('id', 'uuid')->all(),
                ],
            );

            foreach ($resources as $key => $resource) {
                foreach ($changes[$key] ?? [] as $row) {
                    $this->applyRow($household, $key, $resource, $row, $state, $version, $now);
                }
            }

            $outgoing = [];
            foreach ($resources as $key => $resource) {
                $outgoing[$key] = $this->collectOutgoing($household, $resource, $cursor, $state->include[$key] ?? []);
            }

            return [
                'cursor' => $version,
                'household_id' => $household->id,
                'changes' => $outgoing,
                'rejected' => $state->rejected,
                'remaps' => $state->remaps,
            ];
        });
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
     */
    private function applyRow(Household $household, string $key, array $resource, array $row, SyncBatchState $state, int $version, CarbonImmutable $now): void
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

        /** @var (Model&Syncable)|null $model */
        $model = $resource['model']::withTrashed()->where('uuid', $uuid)->first();

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
            $state->maps[$key][$uuid] = $model->getKey();
            if ($this->serverIsNewer($model, $incomingUpdatedAt)) {
                $state->include($key, $uuid);

                return;
            }
            $model->tombstone($incomingDeletedAt, $version);

            return;
        }

        if ($model !== null) {
            $state->maps[$key][$uuid] = $model->getKey();
            if ($this->serverIsNewer($model, $incomingUpdatedAt)) {
                // Keep the server copy and send it back so the client converges.
                $state->include($key, $uuid);

                return;
            }
        } elseif ($key === 'ingredients' && ($existing = $this->sameNamedIngredient($household, $row['name'])) !== null) {
            // Two devices created the same ingredient offline: merge onto the first.
            $state->maps[$key][$uuid] = $existing->id;
            $state->remaps[$key][$uuid] = $existing->uuid;
            $state->include($key, $existing->uuid);

            return;
        } else {
            $model = new $resource['model'];
            $model->setAttribute('uuid', $uuid);
        }

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

        $model->forceFill($attributes)->stampSync($version, $now);
        Model::withoutTimestamps(fn () => $model->save());

        $state->maps[$key][$uuid] = $model->getKey();
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

        return in_array((int) $model->getAttribute($column), $state->maps[$parentKey], true);
    }

    private function sameNamedIngredient(Household $household, string $name): ?Ingredient
    {
        return $household->ingredients()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->first();
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

            $parentId = $state->maps[$parentKey][$parentUuid] ?? null;
            if ($parentId === null) {
                return "Unknown {$column} {$parentUuid}.";
            }
            $resolved[$column] = $parentId;
        }

        return $resolved;
    }

    /**
     * Scope a child's parent relation to the household — live parents only for
     * a first sync, so a fresh client never receives children of a tombstone.
     */
    private function parentScope(Builder $query, Household $household, bool $liveParents): void
    {
        if (! $liveParents) {
            $query->withTrashed();
        }
        $query->where('household_id', $household->id);
    }

    /**
     * Collect the household's rows above the client's cursor (tombstones
     * included), plus any explicitly included uuids.
     *
     * @param  array{with: array<string, callable>, query: callable, serialize: callable}  $resource
     * @param  list<string>  $include
     * @return array<int, array<string, mixed>>
     */
    private function collectOutgoing(Household $household, array $resource, ?int $cursor, array $include): array
    {
        $query = ($resource['query'])($household, $cursor === null)->withTrashed()->with($resource['with']);

        if ($cursor === null) {
            $query->where(fn (Builder $q) => $q->whereNull('deleted_at')->orWhereIn('uuid', $include));
        } else {
            $query->where(fn (Builder $q) => $q->where('sync_version', '>', $cursor)->orWhereIn('uuid', $include));
        }

        return $query->orderBy('id')->get()
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
