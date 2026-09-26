<?php

namespace App\Actions\Sync;

use App\Actions\Dinners\MergeDuplicateDinnerItems;
use App\Enums\DinnerCategory;
use App\Enums\MealType;
use App\Models\Concerns\Syncable;
use App\Models\Dinner;
use App\Models\DinnerImage;
use App\Models\DinnerItem;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\Household;
use App\Models\HouseholdDinnerCategory;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Rules\DinnerCategoryReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
 *     A row identical to the stored one is not rewritten, so re-uploading
 *     what the server already has (an interrupted first sync re-seeds
 *     everything) moves no version and wakes no peer. Ids and parent ids are
 *     compared in lower case, as Postgres stores uuids.
 *  2. Outgoing collects every row in the household with a version above the
 *     client's cursor, tombstones included, plus any row the client pushed but
 *     lost on (so it converges on the server copy). A first sync (no cursor)
 *     gets only live rows whose parents are live. A pull is capped at about a
 *     page of rows (see collectPull), cut between versions: the cursor it
 *     returns is the last version it sent in full, and `has_more` says the
 *     next pull has more.
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

    public function __construct(private AllocateSyncVersion $allocateVersion, private MergeDuplicateDinnerItems $mergeDinnerItems) {}

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
                'fields' => ['name', 'default_unit', 'category', 'category_source'],
                'fks' => [],
                'nullableFks' => [],
                'hasHousehold' => true,
                'query' => fn (Household $h, bool $liveParents): Builder => $h->ingredients()->getQuery(),
                'serialize' => fn (Ingredient $m): array => [
                    'name' => $m->name,
                    'default_unit' => $m->default_unit,
                    'category' => $m->category,
                    'category_source' => $m->category_source,
                ],
                'rules' => [
                    'name' => ['required', 'string', 'max:255'],
                    'default_unit' => ['nullable', 'string', 'max:50'],
                    'category' => ['nullable', 'string', 'max:50'],
                    'category_source' => ['sometimes', 'nullable', 'in:user,dictionary,ai'],
                ],
            ],
            'dinner_categories' => [
                'model' => HouseholdDinnerCategory::class,
                'fields' => ['name'],
                'fks' => [],
                'nullableFks' => [],
                'hasHousehold' => true,
                'query' => fn (Household $h, bool $liveParents): Builder => $h->dinnerCategories()->getQuery(),
                'serialize' => fn (HouseholdDinnerCategory $m): array => ['name' => $m->name],
                'rules' => ['name' => ['required', 'string', 'max:80', 'regex:/\S/u']],
            ],
            'dinners' => [
                'model' => Dinner::class,
                'fields' => ['name', 'default_servings', 'notes', 'category', 'emoji', 'image_path', 'image_thumbhash'],
                'fks' => [],
                'nullableFks' => [],
                'hasHousehold' => true,
                'query' => fn (Household $h, bool $liveParents): Builder => $h->dinners()->getQuery(),
                'serialize' => fn (Dinner $m): array => [
                    'name' => $m->name,
                    'category' => $m->category,
                    'default_servings' => $m->default_servings,
                    'notes' => $m->notes,
                    'emoji' => $m->emoji,
                    'image_path' => $m->image_path,
                    'image_thumbhash' => $m->image_thumbhash,
                ],
                'rules' => [
                    'name' => ['required', 'string', 'max:255'],
                    'default_servings' => ['required', 'integer', 'min:1', 'max:99'],
                    'category' => ['sometimes', 'nullable', 'string', 'max:36'],
                    'notes' => ['nullable', 'string', 'max:5000'],
                    'emoji' => ['sometimes', 'nullable', 'string', 'max:32', 'not_regex:/[\\s<>]/u'],
                    'image_path' => ['sometimes', 'nullable', 'string', 'regex:'.DinnerImage::PATH_PATTERN],
                    'image_thumbhash' => ['sometimes', 'nullable', 'required_with:image_path', 'string', 'max:64', 'regex:/^[A-Za-z0-9+\/=]+$/'],
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
                    // The column (and the REST API) take 255.
                    'notes' => ['nullable', 'string', 'max:255'],
                ],
            ],
            'shopping_lists' => [
                'model' => ShoppingList::class,
                'fields' => ['name', 'archived_at'],
                'fks' => ['dinner_plan_id' => 'dinner_plans'],
                'nullableFks' => ['dinner_plan_id'],
                'hasHousehold' => true,
                'query' => fn (Household $h, bool $liveParents): Builder => $h->shoppingLists()->getQuery(),
                'serialize' => fn (ShoppingList $m): array => [
                    'name' => $m->name,
                    'archived_at' => $m->archived_at?->toISOString(),
                ],
                'rules' => [
                    'dinner_plan_id' => ['nullable', 'uuid'],
                    'name' => ['required', 'string', 'max:255'],
                    'archived_at' => ['sometimes', 'nullable', 'date'],
                ],
            ],
            'shopping_list_items' => [
                'model' => ShoppingListItem::class,
                'fields' => ['name', 'quantity', 'unit', 'is_checked', 'is_generated'],
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
                    'is_generated' => (bool) $m->is_generated,
                ],
                'rules' => [
                    'shopping_list_id' => ['required', 'uuid'],
                    'ingredient_id' => ['nullable', 'uuid'],
                    'name' => ['nullable', 'required_without:ingredient_id', 'string', 'max:255'],
                    'quantity' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
                    'unit' => ['nullable', 'string', 'max:50'],
                    // NOT NULL in the database: omit it rather than send null.
                    'is_checked' => ['sometimes', 'boolean'],
                    'is_generated' => ['sometimes', 'boolean'],
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
     *     has_more?: bool,
     *     next_page?: string|null,
     * }
     */
    public function handle(Household $household, User $user, ?int $cursor, array $changes, bool $paged = false, ?string $page = null): array
    {
        $resources = $this->resources();
        $this->assertBatchSize($changes);
        // A client may ask for the download in pages (`paged`), then follow
        // `next_page` with the same cursor. The first page does everything an
        // unpaged sync does (push, merge, lock); later pages only read on from
        // where the previous one stopped.
        $continuation = $paged ? $this->decodePage($page, count($resources), $cursor !== null) : null;

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
        if ($incoming === [] && $cursor !== null && $cursor >= $current && $continuation === null) {
            return [
                'cursor' => $current,
                'household_id' => $household->id,
                'changes' => array_fill_keys(array_keys($resources), []),
                'rejected' => [],
                'remaps' => [],
                'has_more' => false,
            ];
        }

        return DB::transaction(function () use ($household, $user, $cursor, $incoming, $resources, $paged, $continuation): array {
            $now = CarbonImmutable::now();
            $firstPull = $cursor === null && $continuation === null;
            // A write batch locks the household for the rest of the transaction
            // so peers' write batches wait; a pull just reads the committed version.
            $committed = $this->committedVersion($household, lock: $incoming !== [] || $firstPull);
            if (($incoming !== [] || $firstPull) && ! $household->hasMember($user)) {
                abort(409, 'You are no longer a member of this household.');
            }
            $state = $this->newState($household, $resources);

            foreach ($incoming as $key => $rows) {
                $existing = $this->prefetch($resources[$key], $rows);
                foreach ($rows as $row) {
                    $this->applyRow($household, $key, $resources[$key], $row, $state, $existing, $now, $user->id, $cursor);
                }
            }

            // A first sync tidies the whole household; a push only the dinners it wrote items for.
            if ($firstPull) {
                $this->mergeDinnerItems->handle($household, $state);
            } elseif ($state->writtenDinnerIds() !== []) {
                $this->mergeDinnerItems->handle($household, $state, $state->writtenDinnerIds());
            }

            // Every page reports the version the first page was read at: the
            // client stores it once the last page is in, and its next pull then
            // brings anything that changed while it was paging.
            $version = $continuation['version'] ?? $state->allocatedVersion() ?? $committed;

            if ($paged) {
                [$outgoing, $nextPage] = $this->collectPage($household, $resources, $cursor, $state, $version, $continuation);

                return [
                    'cursor' => $version,
                    'household_id' => $household->id,
                    'changes' => $outgoing,
                    'rejected' => $state->rejected,
                    'remaps' => $state->remaps,
                    'next_page' => $nextPage,
                ];
            }

            // Unpaged: app builds from before paging, which never follow `next_page`.
            [$outgoing, $completeThrough] = $this->collectPull($household, $resources, $cursor, $state);

            return [
                'cursor' => $completeThrough ?? $version,
                'household_id' => $household->id,
                'changes' => $outgoing,
                'rejected' => $state->rejected,
                'remaps' => $state->remaps,
                'has_more' => $completeThrough !== null,
            ];
        });
    }

    /**
     * One page of a paged pull, at most `handlelista.sync_page_rows` rows, in
     * resource (dependency) order, plus — on the first page — every row the
     * push must return whatever its page (a pushed row the server kept its own
     * copy of, tombstones included).
     *
     * A first sync (no cursor) pages through live rows in id order. A pull
     * with a cursor pages through the rows above it up to the version the
     * first page was read at, tombstones included, in (sync_version, id)
     * order; rows written meanwhile have a higher version and come with the
     * next pull.
     *
     * @param  array<string, array{fks: array<string, string>, query: callable(Household, bool): Builder, serialize: callable}>  $resources
     * @param  array{version: int, resource: int, after: int, afterVersion: int|null}|null  $continuation
     * @return array{0: array<string, array<int, array<string, mixed>>>, 1: string|null}
     */
    private function collectPage(Household $household, array $resources, ?int $cursor, SyncBatchState $state, int $version, ?array $continuation): array
    {
        $keys = array_keys($resources);
        $outgoing = array_fill_keys($keys, []);
        $sent = [];
        if ($continuation === null) {
            foreach ($resources as $key => $resource) {
                $include = $state->include[$key] ?? [];
                if ($include === []) {
                    continue;
                }
                $models = ($resource['query'])($household, false)->withTrashed()->whereIn('uuid', $include)->orderBy('id')->get();
                $outgoing[$key] = $this->serializeModels($resource, $models, $state);
                foreach ($models as $model) {
                    $sent[$key][$model->getAttribute('uuid')] = true;
                }
            }
        }

        $budget = max(1, (int) config('handlelista.sync_page_rows'));
        for ($index = $continuation['resource'] ?? 0; $index < count($keys); $index++) {
            $key = $keys[$index];
            $resource = $resources[$key];
            $resumes = $continuation !== null && $index === $continuation['resource'];
            if ($cursor === null) {
                $query = ($resource['query'])($household, true);
                $idColumn = $query->getModel()->qualifyColumn('id');
                $query->where($idColumn, '>', $resumes ? $continuation['after'] : 0)->orderBy($idColumn);
            } else {
                $query = $this->pullQuery($household, $resource, $cursor)->where('sync_version', '<=', $version);
                if ($resumes) {
                    $query->where(fn (Builder $query) => $query
                        ->where('sync_version', '>', $continuation['afterVersion'])
                        ->orWhere(fn (Builder $query) => $query->where('sync_version', $continuation['afterVersion'])->where('id', '>', $continuation['after'])));
                }
            }
            $models = $query->limit($budget + 1)->get();
            $hasMore = $models->count() > $budget;
            $models = $models->take($budget);
            $last = $models->last();
            $models = $models->reject(fn (Model $model) => isset($sent[$key][$model->getAttribute('uuid')]))->values();
            array_push($outgoing[$key], ...$this->serializeModels($resource, $models, $state));
            $budget -= $models->count();

            if ($hasMore) {
                return [$outgoing, $this->encodePage($version, $index, (int) $last->getKey(), $cursor === null ? null : (int) $last->getAttribute('sync_version'))];
            }
            if ($budget <= 0) {
                return [$outgoing, $index + 1 < count($keys) ? $this->encodePage($version, $index + 1, 0, $cursor === null ? null : 0) : null];
            }
        }

        return [$outgoing, null];
    }

    /**
     * @param  array{fks: array<string, string>, serialize: callable}  $resource
     * @param  Collection<int, Model>  $models
     * @return list<array<string, mixed>>
     */
    private function serializeModels(array $resource, Collection $models, SyncBatchState $state): array
    {
        if ($models->isEmpty()) {
            return [];
        }
        foreach ($resource['fks'] as $column => $parentKey) {
            $state->resolveUuids($parentKey, $models->pluck($column)->all());
        }

        return $models->map(fn (Model $model): array => $this->serializeRow($resource, $model, $state))->values()->all();
    }

    /**
     * `version.resource.id` for a first sync; `version.resource.sync_version.id`
     * for a pull with a cursor.
     */
    private function encodePage(int $version, int $resource, int $after, ?int $afterVersion): string
    {
        return implode('.', array_filter([$version, $resource, $afterVersion, $after], fn (?int $part): bool => $part !== null));
    }

    /** @return array{version: int, resource: int, after: int, afterVersion: int|null}|null */
    private function decodePage(?string $page, int $resourceCount, bool $hasCursor): ?array
    {
        if ($page === null) {
            return null;
        }
        $pattern = $hasCursor ? '/^(\d{1,18})\.(\d{1,2})\.(\d{1,18})\.(\d{1,18})$/' : '/^(\d{1,18})\.(\d{1,2})\.(\d{1,18})$/';
        if (preg_match($pattern, $page, $match) !== 1 || (int) $match[2] >= $resourceCount) {
            throw ValidationException::withMessages(['page' => 'That page does not exist.']);
        }

        return $hasCursor
            ? ['version' => (int) $match[1], 'resource' => (int) $match[2], 'afterVersion' => (int) $match[3], 'after' => (int) $match[4]]
            : ['version' => (int) $match[1], 'resource' => (int) $match[2], 'afterVersion' => null, 'after' => (int) $match[3]];
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
     * @param  array<string, array{model: class-string<Model>, query: callable(Household, bool): Builder}>  $resources
     */
    private function newState(Household $household, array $resources): SyncBatchState
    {
        return new SyncBatchState(
            loadMap: fn (string $key): array => $resources[$key]['query']($household, false)
                ->withTrashed()
                ->pluck('id', 'uuid')
                ->all(),
            loadUuids: fn (string $key, array $ids): array => $resources[$key]['query']($household, false)
                ->withTrashed()
                ->whereKey($ids)
                ->pluck('uuid', 'id')
                ->all(),
            loadLiveIds: fn (string $key): array => $resources[$key]['query']($household, true)
                ->pluck('id')
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
            if (is_array($row) && Str::isUuid($row['id'] ?? null)) {
                $uuids[strtolower($row['id'])] = true;
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
    private function applyRow(Household $household, string $key, array $resource, array $row, SyncBatchState $state, array &$existing, CarbonImmutable $now, int $userId, ?int $cursor): void
    {
        // Rejections name the row as the client knows it.
        $clientId = is_string($row['id'] ?? null) ? $row['id'] : null;
        $row = $this->normaliseIds($key, $resource, $row);
        $uuid = $row['id'] ?? null;
        $isTombstone = ! empty($row['deleted_at']);

        $validator = Validator::make($row, $isTombstone ? $this->baseRules() : $this->baseRules() + $resource['rules']);
        if ($validator->fails()) {
            $state->reject($key, $clientId, 'invalid', $validator->errors()->first());

            return;
        }

        try {
            $incomingUpdatedAt = $this->clientTime($row['updated_at'] ?? null, $now);
            $incomingDeletedAt = $isTombstone ? $this->clientTime($row['deleted_at'], $now) : null;
            $incomingCreatedAt = $this->clientTime($row['created_at'] ?? null, $now);
        } catch (Throwable) {
            $state->reject($key, $clientId, 'invalid', 'Timestamps must be ISO-8601.');

            return;
        }

        $model = $existing[$uuid] ?? null;

        if ($model !== null && ! $this->isOwned($model, $resource, $household, $state)) {
            // Another household's row (or a uuid collision): behave as if it did not exist.
            if (! $isTombstone) {
                $state->reject($key, $clientId, 'unknown_id', 'That id belongs to another household.');
            }

            return;
        }

        while ($key === 'dinner_items' && $model?->merged_into_uuid) {
            $canonical = DinnerItem::withTrashed()->where('uuid', $model->merged_into_uuid)->firstOrFail();
            $state->remaps[$key][$uuid] = $canonical->uuid;
            $state->include($key, $uuid);
            $uuid = $canonical->uuid;
            $row['id'] = $uuid;
            $model = $canonical;
            $state->include($key, $uuid);
        }

        if ($model !== null && $model->erasure_version > 0 && ($model->trashed() || ($cursor ?? 0) < $model->erasure_version || ($row['erasure_version'] ?? 0) < $model->erasure_version)) {
            $state->remember($key, $uuid, $model->getKey());
            $state->include($key, $uuid);

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
            $state->markDeleted($key, $model->getKey());
            if ($key === 'ingredients') {
                $state->forgetIngredientName($model->name);
            }

            return;
        }

        if ($model !== null) {
            $state->remember($key, $uuid, $model->getKey());
            if ($this->serverIsNewer($model, $incomingUpdatedAt)) {
                // A human correction wins over automatic enrichment even when
                // the other device finished inference after the correction.
                if ($key === 'ingredients' && ! $model->trashed() && ($row['category_source'] ?? null) === 'user'
                    && in_array($model->category_source, ['ai', 'dictionary'], true)
                    && array_key_exists('category', $row)) {
                    $model->forceFill(['category' => $row['category'], 'category_source' => 'user'])
                        ->attributeContentTo($userId)->loadedUnderHouseholdLock()->stampSync($state->version(), $now);
                    Model::withoutTimestamps(fn () => $model->save());
                }
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
            $model->setAttribute('created_by_user_id', $userId);
        }
        $previousName = $key === 'ingredients' && $model->exists ? $model->getOriginal('name') : null;

        $foreignKeys = $this->resolveForeignKeys($resource, $row, $state);
        if (is_string($foreignKeys)) {
            $state->reject($key, $clientId, 'unknown_parent', $foreignKeys);

            return;
        }

        if ($key === 'dinners' && isset($row['category'])) {
            $categoryValidator = Validator::make($row, ['category' => [new DinnerCategoryReference($household->id, allowDeleted: true)]]);
            if ($categoryValidator->fails()) {
                $state->reject($key, $clientId, Str::isUuid($row['category']) ? 'unknown_parent' : 'invalid', $categoryValidator->errors()->first());

                return;
            }
            // A stale offline recipe edit keeps its content but cannot reattach a deleted label.
            if (DinnerCategory::tryFrom($row['category']) === null
                && ! $household->dinnerCategories()->where('uuid', $row['category'])->exists()) {
                $row['category'] = null;
            }
        }
        // A picture can only be attached from the household's own uploads.
        if ($key === 'dinners' && isset($row['image_path']) && $row['image_path'] !== $model->image_path
            && ! DinnerImage::query()->where('household_id', $household->id)->where('path', $row['image_path'])->exists()) {
            $state->reject($key, $clientId, 'invalid', 'Unknown image.');

            return;
        }
        if ($key === 'dinner_categories') {
            $row['name'] = preg_replace('/\s+/u', ' ', trim($row['name']));
        }

        $attributes = $foreignKeys;
        foreach ($resource['fields'] as $field) {
            if (array_key_exists($field, $row)) {
                $attributes[$field] = $row[$field];
            }
        }
        if ($key === 'ingredients' && $model->exists && array_key_exists('category', $row)
            && ! array_key_exists('category_source', $row) && $row['category'] !== $model->category) {
            $attributes['category_source'] = 'user';
        }
        if ($key === 'ingredients' && $model->exists && $model->category_source === 'user'
            && in_array($row['category_source'] ?? null, ['ai', 'dictionary'], true)) {
            $attributes['category'] = $model->category;
            $attributes['category_source'] = 'user';
        }
        if ($resource['hasHousehold']) {
            $attributes['household_id'] = $household->id;
        }
        $attributes['created_at'] = $model->exists ? $model->created_at : $incomingCreatedAt;
        $attributes['updated_at'] = $incomingUpdatedAt;
        $attributes['deleted_at'] = null; // A newer live version restores a tombstone.

        $model->forceFill($attributes);
        // The stored row already says this (the client timestamps aside): a
        // re-upload. Rewriting it would give it a new version, which every
        // peer would download again and be notified about.
        if ($model->exists && ! $model->isDirty(array_diff(array_keys($attributes), ['created_at', 'updated_at']))) {
            $model->discardChanges();
        } else {
            // Every existing row here was read after the batch took the household lock.
            $model->attributeContentTo($userId)->loadedUnderHouseholdLock()->stampSync($state->version(), $now);
            Model::withoutTimestamps(fn () => $model->save());
            if ($key === 'dinner_items') {
                $state->wroteItemFor((int) $model->getAttribute('dinner_id'));
            }
        }

        $existing[$uuid] = $model;
        $state->remember($key, $uuid, $model->getKey());
        $state->markLive($key, $model->getKey());
        if ($key === 'ingredients') {
            if ($previousName !== null && SyncBatchState::nameKey($previousName) !== SyncBatchState::nameKey($model->name)) {
                $state->forgetIngredientName($previousName);
            }
            $state->rememberIngredientName($model->name, $model->getKey(), $model->uuid);
        }
    }

    /**
     * Lower-case the row's uuid and parent uuids. Postgres stores (and
     * returns) uuids in lower case, so an upper-case id would miss its stored
     * row and then collide with it on insert.
     *
     * @param  array{fks: array<string, string>}  $resource
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseIds(string $key, array $resource, array $row): array
    {
        $columns = ['id', ...array_keys($resource['fks'])];
        if ($key === 'dinners') {
            $columns[] = 'category'; // a custom category's uuid, or a built-in slug
        }
        foreach ($columns as $column) {
            if (is_string($row[$column] ?? null) && Str::isUuid($row[$column])) {
                $row[$column] = strtolower($row[$column]);
            }
        }

        return $row;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function baseRules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'erasure_version' => ['sometimes', 'integer', 'min:0'],
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

            $parentId = is_string($parentUuid) ? $state->liveId($parentKey, $parentUuid) : null;
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
     * The rows a pull returns: the household's rows above the client's cursor,
     * tombstones included (a first sync: every live row with live parents),
     * plus the rows the push must send back whatever their version.
     *
     * A device far behind (cursor 0, or months offline) would otherwise get the
     * household's whole history in one response, so a pull stops after about
     * `handlelista.sync_page_rows` rows, walking (sync_version, id). It stops
     * between versions, never inside one (a single version larger than a page
     * goes out whole), and returns the last version it sent in full. Every app
     * build stores the cursor it is given and pulls again, so it catches up
     * over its next pulls without skipping anything; newer builds pull again
     * at once when `has_more` is set. Rows sent above that cursor (the ones
     * this push must return) simply come again.
     *
     * @param  array<string, array{fks: array<string, string>, query: callable(Household, bool): Builder, serialize: callable}>  $resources
     * @return array{0: array<string, list<array<string, mixed>>>, 1: int|null} the rows, and — when not everything fit — the version they are complete through
     */
    private function collectPull(Household $household, array $resources, ?int $cursor, SyncBatchState $state): array
    {
        $budget = max(1, (int) config('handlelista.sync_page_rows'));
        $rows = [];
        $versions = [];
        foreach ($resources as $key => $resource) {
            $rows[$key] = $this->pullQuery($household, $resource, $cursor)->limit($budget + 1)->get();
            array_push($versions, ...$rows[$key]->map(fn (Model $model): int => (int) $model->getAttribute('sync_version'))->all());
        }

        $completeThrough = null;
        if (count($versions) > $budget) {
            sort($versions);
            $firstLeftOut = $versions[$budget];
            $completeThrough = $versions[0] < $firstLeftOut ? $firstLeftOut - 1 : $firstLeftOut;
            foreach ($rows as $key => $models) {
                // A table that filled its fetch may hold more rows at or below the cut.
                $rows[$key] = $models->count() > $budget && (int) $models->last()->getAttribute('sync_version') <= $completeThrough
                    ? $this->pullQuery($household, $resources[$key], $cursor)->where('sync_version', '<=', $completeThrough)->get()
                    : $models->filter(fn (Model $model): bool => (int) $model->getAttribute('sync_version') <= $completeThrough)->values();
            }
        }

        $outgoing = [];
        foreach ($resources as $key => $resource) {
            $models = $rows[$key];
            $include = array_values(array_diff(array_unique($state->include[$key] ?? []), $models->pluck('uuid')->all()));
            if ($include !== []) {
                $models = $models->concat(($resource['query'])($household, false)->withTrashed()->whereIn('uuid', $include)->get());
            }
            $outgoing[$key] = $this->serializeModels($resource, $models, $state);
        }

        return [$outgoing, $completeThrough];
    }

    /**
     * A pull's rows of one resource in (sync_version, id) order, on the
     * `(household or parent, sync_version)` index.
     *
     * @param  array{query: callable(Household, bool): Builder}  $resource
     */
    private function pullQuery(Household $household, array $resource, ?int $cursor): Builder
    {
        $query = ($resource['query'])($household, $cursor === null)->withTrashed();
        if ($cursor === null) {
            $query->whereNull('deleted_at');
        } else {
            $query->where('sync_version', '>', $cursor);
        }

        return $query->orderBy('sync_version')->orderBy('id');
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
            'erasure_version' => (int) $model->getAttribute('erasure_version'),
        ]);
    }
}
