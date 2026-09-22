<?php

namespace Database\Factories;

use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminAction>
 */
class AdminActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_id' => User::factory()->admin(),
            'admin_name' => fake()->name(),
            'action' => AdminAction::USER_UPDATED,
            'subject_user_id' => User::factory(),
            'subject_label' => fake()->name(),
            'changes' => ['name' => ['from' => 'Old', 'to' => 'New']],
            'created_at' => now(),
        ];
    }
}
