<?php

namespace App\Actions\Sync;

/**
 * Mutable bookkeeping for one sync batch: the uuid→id maps used to resolve
 * parents, the rows the server refused, the uuids it merged, and the rows it
 * must send back regardless of the cursor.
 */
class SyncBatchState
{
    /**
     * @param  array<string, array<string, int>>  $maps  resource → uuid → internal id
     * @param  array<string, array<int, array{id: ?string, code: string, message: string}>>  $rejected
     * @param  array<string, array<string, string>>  $remaps  resource → pushed uuid → uuid to use instead
     * @param  array<string, list<string>>  $include  resource → uuids to return even below the cursor
     */
    public function __construct(
        public array $maps,
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
}
