<?php

namespace App\Actions\Sync;

use Closure;

/**
 * Mutable bookkeeping for one sync batch: the uuid→id maps used to resolve
 * parents (loaded lazily, one query per resource, only when a row needs
 * them), the rows the server refused, the uuids it merged, the rows it must
 * send back regardless of the cursor, and the batch's shared version.
 */
class SyncBatchState
{
    /** @var array<string, array<string, int>> resource → uuid → internal id */
    private array $maps = [];

    /** @var array<string, array<int, string>> resource → internal id → uuid (full or partial) */
    private array $uuids = [];

    /** @var array<string, array<int, true>> resource → set of ids owned by the household */
    private array $owned = [];

    /** @var array<string, array<string, true>> resource → pushed uuids merged onto another row (never a row's own uuid) */
    private array $aliases = [];

    /** @var array<string, array{id: int, uuid: string}> lower-cased live ingredient name → identity */
    private ?array $ingredientsByName = null;

    /** The version stamped on this batch's writes; allocated on the first write. */
    private ?int $version = null;

    /**
     * @param  Closure(string): array<string, int>  $loadMap  full uuid→id map of a resource (trashed included)
     * @param  Closure(string, list<int>): array<int, string>  $loadUuids  id→uuid for just the given ids
     * @param  Closure(): array<string, array{id: int, uuid: string}>  $loadIngredientNames  live ingredients by lower-cased name
     * @param  Closure(): int  $allocateVersion
     * @param  array<string, array<int, array{id: ?string, code: string, message: string}>>  $rejected
     * @param  array<string, array<string, string>>  $remaps  resource → pushed uuid → uuid to use instead
     * @param  array<string, list<string>>  $include  resource → uuids to return even below the cursor
     */
    public function __construct(
        private Closure $loadMap,
        private Closure $loadUuids,
        private Closure $loadIngredientNames,
        private Closure $allocateVersion,
        public array $rejected = [],
        public array $remaps = [],
        public array $include = [],
    ) {}

    public function reject(string $resource, ?string $uuid, string $code, string $message): void
    {
        $this->rejected[$resource][] = ['id' => $uuid, 'code' => $code, 'message' => $message];
    }

    public function include(string $resource, string $uuid): void
    {
        $this->include[$resource][] = $uuid;
    }

    /** The batch version, allocating it (and taking the household lock) on first use. */
    public function version(): int
    {
        return $this->version ??= ($this->allocateVersion)();
    }

    /** The version if any write happened, else null. */
    public function allocatedVersion(): ?int
    {
        return $this->version;
    }

    /** Internal id for a parent uuid, or null if the household has no such row. */
    public function id(string $resource, string $uuid): ?int
    {
        return $this->map($resource)[$uuid] ?? null;
    }

    /** Whether the household owns the row with this internal id. */
    public function owns(string $resource, int $id): bool
    {
        $this->owned[$resource] ??= array_fill_keys(array_values($this->map($resource)), true);

        return isset($this->owned[$resource][$id]);
    }

    /** Record a row the batch wrote (or resolved) so later rows can reference it. */
    public function remember(string $resource, string $uuid, int $id): void
    {
        $this->map($resource)[$uuid] = $id;
        $this->uuids[$resource][$id] = $uuid;
        if (isset($this->owned[$resource])) {
            $this->owned[$resource][$id] = true;
        }
    }

    /**
     * Let a pushed uuid resolve to an existing row it was merged onto, without
     * making it that row's outgoing identity.
     */
    public function alias(string $resource, string $uuid, int $id): void
    {
        $this->map($resource)[$uuid] = $id;
        $this->aliases[$resource][$uuid] = true;
    }

    /**
     * Make `uuidOf()` answer for these ids: from the full map when it is already
     * loaded, else with one query for exactly the ids still unknown.
     *
     * @param  list<int|null>  $ids
     */
    public function resolveUuids(string $resource, array $ids): void
    {
        $missing = [];
        foreach ($ids as $id) {
            if ($id !== null && ! isset($this->uuids[$resource][$id])) {
                $missing[$id] = true;
            }
        }
        if ($missing === []) {
            return;
        }

        if (isset($this->maps[$resource])) {
            $flipped = [];
            foreach ($this->maps[$resource] as $uuid => $id) {
                if (! isset($this->aliases[$resource][$uuid])) {
                    $flipped[$id] = $uuid;
                }
            }
            $this->uuids[$resource] = $flipped + ($this->uuids[$resource] ?? []);

            return;
        }

        $this->uuids[$resource] = ($this->loadUuids)($resource, array_keys($missing)) + ($this->uuids[$resource] ?? []);
    }

    /** The uuid for a parent id previously passed to `resolveUuids()`. */
    public function uuidOf(string $resource, ?int $id): ?string
    {
        return $id === null ? null : ($this->uuids[$resource][$id] ?? null);
    }

    /**
     * A live ingredient with this name (case-insensitive), if any.
     *
     * @return array{id: int, uuid: string}|null
     */
    public function ingredientNamed(string $name): ?array
    {
        $this->ingredientsByName ??= ($this->loadIngredientNames)();

        return $this->ingredientsByName[self::nameKey($name)] ?? null;
    }

    /** Keep the loaded name map current; an unloaded map reads the saved row from the database later. */
    public function rememberIngredientName(string $name, int $id, string $uuid): void
    {
        if ($this->ingredientsByName !== null) {
            $this->ingredientsByName[self::nameKey($name)] = ['id' => $id, 'uuid' => $uuid];
        }
    }

    public function forgetIngredientName(string $name): void
    {
        if ($this->ingredientsByName !== null) {
            unset($this->ingredientsByName[self::nameKey($name)]);
        }
    }

    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @return array<string, int>
     */
    private function &map(string $resource): array
    {
        if (! isset($this->maps[$resource])) {
            $this->maps[$resource] = ($this->loadMap)($resource);
        }

        return $this->maps[$resource];
    }
}
