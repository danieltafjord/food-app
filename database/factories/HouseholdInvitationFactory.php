<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HouseholdInvitation>
 */
class HouseholdInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            // Sent by one of the household's owners, when it has one.
            'invited_by_user_id' => fn (array $attributes): ?int => Household::query()->find($attributes['household_id'])
                ?->members()->wherePivot('role', HouseholdRole::Owner->value)->value('users.id'),
            'email' => fake()->unique()->safeEmail(),
            'role' => HouseholdRole::Member,
            'token' => Str::random(48),
            'accepted_at' => null,
            'declined_at' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['accepted_at' => now()]);
    }
}
