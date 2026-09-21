<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use App\Models\Concerns\TracksContentAuthors;
use Database\Factories\ShoppingListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingListItem extends Model
{
    /** @use HasFactory<ShoppingListItemFactory> */
    use HasFactory, Syncable, TracksContentAuthors;

    /** @var list<string> */
    protected $fillable = [
        'shopping_list_id',
        'ingredient_id',
        'name',
        'quantity',
        'unit',
        'is_checked',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_checked' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'is_checked' => 'boolean',
        ];
    }

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function syncHouseholdId(): int
    {
        if ($this->relationLoaded('shoppingList') && $this->shoppingList !== null) {
            return (int) $this->shoppingList->household_id;
        }

        return (int) ShoppingList::withTrashed()->whereKey($this->shopping_list_id)->value('household_id');
    }

    /** @return array<string, mixed> */
    public function contentErasureDefaults(): array
    {
        return ['name' => null, 'quantity' => null, 'unit' => null, 'is_checked' => false];
    }
}
