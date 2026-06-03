<?php

namespace App\Data\Sync;

use Spatie\LaravelData\Data;

/**
 * The mobile client's sync envelope.
 *
 * `lastSync` is the opaque `server_time` cursor returned by the previous sync
 * (null on the very first sync → pull everything). `changes` is a map of
 * resource key → list of rows the client has touched since then, each row in
 * the client's snake_case shape with a `uuid` `id` and client timestamps.
 *
 * @property array<string, array<int, array<string, mixed>>> $changes
 */
class SyncRequestData extends Data
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $changes
     */
    public function __construct(
        public ?string $lastSync,
        public array $changes = [],
    ) {}

    /**
     * A pull-only sync sends no changes, so `changes` must accept an empty
     * array rather than the default "required" rule for a typed array.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'last_sync' => ['nullable', 'string'],
            'changes' => ['nullable', 'array'],
        ];
    }
}
