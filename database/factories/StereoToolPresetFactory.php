<?php

namespace Database\Factories;

use App\Models\Station;
use App\Models\StereoToolPreset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<StereoToolPreset>
 */
class StereoToolPresetFactory extends Factory
{
    protected $model = StereoToolPreset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'uploaded_by' => null,
            'name' => fake()->words(2, true),
            // Must sit in the station's own directory: that is what Station deletion drops.
            'path' => fn (array $attributes) => 'stereo-tool-presets/'.$attributes['station_id'].'/'.Str::uuid().'.sts',
            'size' => fake()->numberBetween(200, 50_000),
        ];
    }

    /** Requires Storage::fake('local') in the test. */
    public function withFile(string $contents = "[Preset info]\nName=Test preset\n"): static
    {
        return $this->afterCreating(function (StereoToolPreset $preset) use ($contents) {
            Storage::disk('local')->put($preset->path, $contents);

            $preset->update(['size' => strlen($contents)]);
        });
    }
}
