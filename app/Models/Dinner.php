<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use App\Models\Concerns\TracksContentAuthors;
use Carbon\CarbonInterface;
use Database\Factories\DinnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dinner extends Model
{
    /** @use HasFactory<DinnerFactory> */
    use HasFactory, Syncable, TracksContentAuthors;

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'created_by_user_id',
        'name',
        'default_servings',
        'notes',
        'category',
        'emoji',
        'image_path',
        'image_thumbhash',
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

    public function syncHouseholdId(): int
    {
        return (int) $this->household_id;
    }

    protected function tombstoneChildren(CarbonInterface $deletedAt, int $version): void
    {
        // Leaf tables: one UPDATE each, no per-row model round-trips.
        $this->items()->update($this->tombstoneStamp($deletedAt, $version));
        $this->planEntries()->update($this->tombstoneStamp($deletedAt, $version));
    }

    /** @return array<string, mixed> */
    public function contentErasureDefaults(): array
    {
        return ['name' => 'Recipe', 'default_servings' => 1, 'notes' => null, 'category' => null,
            'emoji' => null, 'image_path' => null, 'image_thumbhash' => null];
    }
}
