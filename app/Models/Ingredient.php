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
        $this->dinnerItems()->get()->each(fn (DinnerItem $item) => $item->tombstone($deletedAt, $version));

        $this->shoppingListItems()->get()->each(function (ShoppingListItem $item) use ($version): void {
            $item->forceFill(['ingredient_id' => null, 'name' => $item->name ?? $this->name])
                ->stampSync($version)
                ->save();
        });
    }
}
