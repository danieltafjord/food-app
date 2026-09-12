<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Carbon\CarbonInterface;
use Database\Factories\ShoppingListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShoppingList extends Model
{
    /** @use HasFactory<ShoppingListFactory> */
    use HasFactory, Syncable;

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'dinner_plan_id',
        'created_by_user_id',
        'name',
    ];

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<DinnerPlan, $this> */
    public function dinnerPlan(): BelongsTo
    {
        return $this->belongsTo(DinnerPlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<ShoppingListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }

    public function syncHouseholdId(): int
    {
        return (int) $this->household_id;
    }

    protected function tombstoneChildren(CarbonInterface $deletedAt, int $version): void
    {
        $this->items()->update($this->tombstoneStamp($deletedAt, $version));
    }
}
