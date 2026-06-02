<?php

namespace Database\Factories;

use App\Models\DinnerPlan;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DinnerPlan>
 */
class DinnerPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 week', '+1 week');

        return [
            'household_id' => Household::factory(),
            'created_by_user_id' => null,
            'name' => 'Week of '.$start->format('M j'),
            'start_date' => $start,
            'end_date' => (clone $start)->modify('+6 days'),
        ];
    }
}
