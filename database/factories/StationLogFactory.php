<?php

namespace Database\Factories;

use App\Models\Station;
use App\Models\StationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StationLog>
 */
class StationLogFactory extends Factory
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
            'event' => StationLog::EVENT_TRACK,
            'source' => 'playlist',
            'media_file_id' => null,
            'generated_playlist_item_id' => null,
            'generated_playlist_id' => null,
            'title' => $this->faker->sentence(3),
            'artist' => $this->faker->name(),
            'source_type' => 'template_item',
            'message' => null,
            'occurred_at' => now(),
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => [
            'event' => StationLog::EVENT_TRACK,
            'source' => 'live',
            'source_type' => null,
        ]);
    }
}
