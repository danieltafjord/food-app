<?php

namespace App\Models;

use App\Enums\MealType;
use Database\Factories\DinnerPlanEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DinnerPlanEntry extends Model
{
    /** @use HasFactory<DinnerPlanEntryFactory> */
    use HasFactory;

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
}
