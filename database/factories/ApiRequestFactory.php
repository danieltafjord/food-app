<?php

namespace Database\Factories;

use App\Models\ApiRequest;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiRequest>
 */
class ApiRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'channel' => ApiRequest::CHANNEL_APP,
            'user_id' => User::factory(),
            'household_id' => Household::factory(),
            'method' => 'GET',
            'path' => 'api/v1/me',
            'route' => 'api.v1.me',
            'status' => 200,
            'duration_ms' => fake()->numberBetween(5, 400),
            'ip' => fake()->ipv4(),
            'user_agent' => 'Handlelista/1.0 (iOS)',
            'request_body' => null,
            'response_body' => '{"data":{}}',
            'created_at' => now(),
        ];
    }

    public function failed(int $status = 500): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'response_body' => '{"message":"Server Error"}',
            'error' => 'RuntimeException: Something broke',
        ]);
    }

    public function public(): static
    {
        return $this->state(fn () => ['channel' => ApiRequest::CHANNEL_PUBLIC, 'path' => 'api/public/v1/today', 'route' => 'api.public.v1.today']);
    }
}
