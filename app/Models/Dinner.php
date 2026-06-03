<?php

namespace App\Models;

use App\Models\Concerns\HasSyncIdentity;
use Database\Factories\DinnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dinner extends Model
{
    /** @use HasFactory<DinnerFactory> */
    use HasFactory, HasSyncIdentity;

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'created_by_user_id',
        'name',
        'default_servings',
        'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'default_servings' => 2,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_servings' => 'integer',
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

    /** @return HasMany<DinnerItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DinnerItem::class);
    }

    /**
     * The ingredients used in this dinner, with their per-recipe quantity and unit.
     *
     * @return BelongsToMany<Ingredient, $this>
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'dinner_items')
            ->withPivot(['quantity', 'unit'])
            ->withTimestamps();
    }

    /** @return HasMany<DinnerPlanEntry, $this> */
    public function planEntries(): HasMany
    {
        return $this->hasMany(DinnerPlanEntry::class);
    }
}
