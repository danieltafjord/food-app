<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use App\Models\Concerns\TracksContentAuthors;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class HouseholdDinnerCategory extends Model
{
    use Syncable, TracksContentAuthors;

    protected $table = 'dinner_categories';

    protected $fillable = ['household_id', 'name'];

    public function syncHouseholdId(): int
    {
        return (int) $this->household_id;
    }

    protected function tombstoneChildren(CarbonInterface $deletedAt, int $version): void
    {
        // Removing a label must never remove a recipe or its planned meals.
        // Preserve the recipe's edit clock so an unrelated offline edit can still merge.
        Dinner::withoutTimestamps(fn () => Dinner::query()->where('household_id', $this->household_id)->where('category', $this->uuid)->update([
            'category' => null,
            'sync_version' => $version,
            'synced_at' => $this->fromDateTime(now()),
        ]));
    }

    public function contentErasureDefaults(): array
    {
        return ['name' => 'Category'];
    }
}
