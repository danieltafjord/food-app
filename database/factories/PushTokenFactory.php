<?php

namespace Database\Factories;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushToken>
 */
class PushTokenFactory extends Factory
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
            'token' => 'ExponentPushToken['.Str::random(22).']',
            'platform' => fake()->randomElement(['ios', 'android']),
            'timezone' => 'Europe/Oslo',
        ];
    }
}
