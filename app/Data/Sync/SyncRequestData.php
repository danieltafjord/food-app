<?php

namespace App\Data\Sync;

use Spatie\LaravelData\Data;

/**
 * The mobile client's sync envelope.
 *
 * `cursor` is the opaque integer returned by the previous sync (null on the
 * very first sync → pull everything live). `householdId` is the household the
 * client believes it is bound to; a mismatch with the active household is
 * refused so a device never uploads one household's rows into another.
 * `changes` maps resource key → rows the client has touched since the cursor,
 * each in the client's snake_case shape with a `uuid` `id` and client
 * timestamps.
 *
 * @property array<string, array<int, array<string, mixed>>> $changes
 */
class SyncRequestData extends Data
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $changes
     */
    public function __construct(
        public ?int $cursor,
        public ?int $householdId,
        public array $changes = [],
    ) {}

    /**
     * Only the envelope is validated here; rows are validated one by one by
     * the action so a bad row is reported rather than failing the batch.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'cursor' => ['nullable', 'integer', 'min:0'],
            'household_id' => ['nullable', 'integer'],
            'changes' => ['nullable', 'array:dinner_categories,ingredients,dinners,dinner_items,dinner_plans,plan_entries,shopping_lists,shopping_list_items'],
            'changes.*' => ['array'],
            'changes.*.*' => ['array'],
        ];
    }
}
