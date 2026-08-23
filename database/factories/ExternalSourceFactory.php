<?php

namespace Database\Factories;

use App\Models\ExternalSource;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalSource>
 */
class ExternalSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'name' => ucwords($this->faker->words(2, true)),
            'kind' => 'url',
            'url' => $this->faker->url(),
            'expected_duration_seconds' => $this->faker->numberBetween(120, 600),
            'prefetch_lead_seconds' => 180,
            'freshness_seconds' => 0,
            'normalize' => true,
            'trim_leading_silence' => false,
            'fade_in' => false,
        ];
    }

    public function news(): static
    {
        return $this->state(['kind' => 'news', 'url' => null, 'name' => 'Nachrichten (laut.fm)']);
    }

    public function weather(): static
    {
        return $this->state(['kind' => 'weather', 'url' => null, 'name' => 'Wetter (laut.fm)']);
    }
}
