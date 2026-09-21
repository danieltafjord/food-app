<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use App\Models\Concerns\TracksContentAuthors;
use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory, TracksContentAuthors;

    /** @var list<string> */
    protected $fillable = ['name', 'default_servings'];

    /**
     * `sync_version` is locked and incremented by every syncable write — see
     * AllocateSyncVersion.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'default_servings' => 2,
        'sync_version' => 0,
        'erasure_version' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_servings' => 'integer',
            'sync_version' => 'integer',
        ];
    }

    /**
     * The users that belong to this household.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<Dinner, $this> */
    public function dinners(): HasMany
    {
        return $this->hasMany(Dinner::class);
    }

    /** @return HasMany<Ingredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    /** @return HasMany<DinnerPlan, $this> */
    public function dinnerPlans(): HasMany
    {
        return $this->hasMany(DinnerPlan::class);
    }

    /** @return HasMany<ShoppingList, $this> */
    public function shoppingLists(): HasMany
    {
        return $this->hasMany(ShoppingList::class);
    }

    /** @return HasMany<HouseholdInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(HouseholdInvitation::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->getKey())->exists();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->members()
            ->whereKey($user->getKey())
            ->wherePivot('role', HouseholdRole::Owner->value)
            ->exists();
    }

    public function ownerCount(): int
    {
        return $this->members()
            ->wherePivot('role', HouseholdRole::Owner->value)
            ->count();
    }

    /** @return array<string, mixed> */
    public function contentErasureDefaults(): array
    {
        return ['name' => 'Household', 'default_servings' => 2];
    }
}
