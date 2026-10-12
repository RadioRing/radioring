<?php

namespace Database\Factories;

use App\Models\MediaFile;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaFile>
 */
class MediaFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucwords($this->faker->words(2, true));

        return [
            'tenant_id' => Tenant::factory(),
            'title' => $title,
            'artist' => $this->faker->name(),
            'album' => ucwords($this->faker->words(2, true)),
            'type' => 'music',
            'file_path' => 'tenants/demo/media/'.Str::slug($title).'-'.Str::random(6).'.mp3',
            'duration_seconds' => $this->faker->numberBetween(120, 300),
        ];
    }

    /** A 200 s file trimmed in the waveform editor: plays from 5 s to 190 s, with fades. */
    public function withCuePoints(): static
    {
        return $this->state(fn (array $attributes): array => [
            'duration_seconds' => 200,
            'cue_in_seconds' => 5.0,
            'cue_out_seconds' => 190.0,
            'fade_in_seconds' => 1.5,
            'fade_out_seconds' => 3.0,
        ]);
    }
}
