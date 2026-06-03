<?php

namespace App\Models;

use App\Models\Concerns\HasSyncIdentity;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory, HasSyncIdentity;

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
}
