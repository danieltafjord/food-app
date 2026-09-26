<?php

namespace App\Models;

use App\Enums\MealType;
use App\Models\Concerns\Syncable;
use App\Models\Concerns\TracksContentAuthors;
use App\Observers\DinnerPlanEntryObserver;
use Database\Factories\DinnerPlanEntryFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(DinnerPlanEntryObserver::class)]
class DinnerPlanEntry extends Model
{
    /** @use HasFactory<DinnerPlanEntryFactory> */
    use HasFactory, Syncable, TracksContentAuthors;

    /** @var list<string> */
    protected $fillable = [
        'dinner_plan_id',
        'dinner_id',
        'scheduled_date',
        'servings',
        'meal_type',
        'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'meal_type' => MealType::Dinner->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'servings' => 'integer',
            'meal_type' => MealType::class,
        ];
    }

    /** @return BelongsTo<DinnerPlan, $this> */
    public function dinnerPlan(): BelongsTo
    {
        return $this->belongsTo(DinnerPlan::class);
    }

    /** @return BelongsTo<Dinner, $this> */
    public function dinner(): BelongsTo
    {
        return $this->belongsTo(Dinner::class);
    }

    public function syncHouseholdId(): int
    {
        if ($this->relationLoaded('dinnerPlan') && $this->dinnerPlan !== null) {
            return (int) $this->dinnerPlan->household_id;
        }

        return (int) DinnerPlan::withTrashed()->whereKey($this->dinner_plan_id)->value('household_id');
    }

    /** @return array<string, mixed> */
    public function contentErasureDefaults(): array
    {
        return ['scheduled_date' => '1970-01-01', 'servings' => 1, 'meal_type' => MealType::Dinner->value, 'notes' => null];
    }
}
