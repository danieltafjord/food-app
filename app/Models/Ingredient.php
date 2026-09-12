<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Carbon\CarbonInterface;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory, Syncable;

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'name',
        'default_unit',
        'category',
    ];

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<DinnerItem, $this> */
    public function dinnerItems(): HasMany
    {
        return $this->hasMany(DinnerItem::class);
    }

    /** @return HasMany<ShoppingListItem, $this> */
    public function shoppingListItems(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }

    public function syncHouseholdId(): int
    {
        return (int) $this->household_id;
    }

    /**
     * A deleted ingredient leaves recipes (its dinner items are tombstoned) but
     * shopping lists keep the line as free text so nothing vanishes mid-shop.
     */
    protected function tombstoneChildren(CarbonInterface $deletedAt, int $version): void
    {
        $this->dinnerItems()->update($this->tombstoneStamp($deletedAt, $version));

        // Lines without their own text inherit the ingredient's name, then every
        // line is detached — two bulk statements instead of a save per line.
        $this->shoppingListItems()->whereNull('name')->update(['name' => $this->name]);
        $this->shoppingListItems()->update([
            'ingredient_id' => null,
            'sync_version' => $version,
            'synced_at' => now(),
        ]);
    }
}
