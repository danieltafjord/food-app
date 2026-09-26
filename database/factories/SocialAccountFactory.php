<?php

namespace Database\Factories;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => SocialProvider::Google,
            'provider_user_id' => (string) fake()->unique()->randomNumber(9),
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    public function apple(): static
    {
        return $this->state(fn (): array => [
            'provider' => SocialProvider::Apple,
            'provider_user_id' => '001234.'.Str::random(32).'.1234',
        ]);
    }
}
