<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Carbon\CarbonInterface;
use Database\Factories\DinnerPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DinnerPlan extends Model
{
    /** @use HasFactory<DinnerPlanFactory> */
    use HasFactory, Syncable;

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'created_by_user_id',
        'name',
        'start_date',
        'end_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<DinnerPlanEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(DinnerPlanEntry::class);
    }

    /** @return HasMany<ShoppingList, $this> */
    public function shoppingLists(): HasMany
    {
        return $this->hasMany(ShoppingList::class);
    }

    public function syncHouseholdId(): int
    {
        return (int) $this->household_id;
    }

    protected function tombstoneChildren(CarbonInterface $deletedAt, int $version): void
    {
        $this->entries()->update($this->tombstoneStamp($deletedAt, $version));
    }
}
