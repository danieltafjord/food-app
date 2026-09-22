<?php

namespace Database\Factories;

use App\Models\AiRequest;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRequest>
 */
class AiRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'household_id' => Household::factory(),
            'feature' => fake()->randomElement(['categorization', 'suggestions']),
            'model' => 'google/gemini-3.5-flash-lite',
            'status' => AiRequest::STATUS_OK,
            'duration_ms' => fake()->numberBetween(200, 3000),
            'input_tokens' => fake()->numberBetween(100, 800),
            'output_tokens' => fake()->numberBetween(10, 120),
            'cost' => fake()->randomFloat(6, 0.00001, 0.001),
            'created_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => AiRequest::STATUS_FAILED, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0]);
    }

    public function cached(): static
    {
        return $this->state(fn () => ['status' => AiRequest::STATUS_CACHED, 'duration_ms' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0]);
    }
}
