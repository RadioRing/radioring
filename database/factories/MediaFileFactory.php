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
            'fade_in' => false,
            'file_path' => 'tenants/demo/media/'.Str::slug($title).'-'.Str::random(6).'.mp3',
            'duration_seconds' => $this->faker->numberBetween(120, 300),
        ];
    }
}
