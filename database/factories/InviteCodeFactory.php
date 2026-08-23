<?php

namespace Database\Factories;

use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InviteCode>
 */
class InviteCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => InviteCode::generateCode(),
            'note' => null,
            'created_by' => null,
            'used_by_user_id' => null,
            'used_at' => null,
        ];
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'used_at' => now(),
            'used_by_user_id' => User::factory(),
        ]);
    }
}
