<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use App\Models\Concerns\TracksContentAuthors;
use Carbon\CarbonInterface;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory, Syncable, TracksContentAuthors;

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'name',
        'default_unit',
        'category',
        'category_source',
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

    /** Remove deleted ingredients from recipes and shopping lists on every device. */
    protected function tombstoneChildren(CarbonInterface $deletedAt, int $version): void
    {
        $this->dinnerItems()->update($this->tombstoneStamp($deletedAt, $version));

        $this->shoppingListItems()->update($this->tombstoneStamp($deletedAt, $version));
    }

    /** @return array<string, mixed> */
    public function contentErasureDefaults(): array
    {
        return ['name' => 'Ingredient '.$this->uuid, 'default_unit' => null, 'category' => null];
    }
}
