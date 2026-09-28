<?php

namespace Database\Factories;

use App\Enums\StationAlertType;
use App\Models\Station;
use App\Models\StationAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StationAlert>
 */
class StationAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'type' => StationAlertType::Silence,
            'started_at' => now()->subMinutes(5),
            'notified_at' => null,
            'resolved_at' => null,
            'resolved_notified_at' => null,
        ];
    }

    public function notified(): static
    {
        return $this->state(fn () => ['notified_at' => now()->subMinutes(2)]);
    }
}
