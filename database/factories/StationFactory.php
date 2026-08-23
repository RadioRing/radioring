<?php

namespace Database\Factories;

use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'user_id' => User::factory(),
            // The station lives in its owner's tenant, so both share one media library.
            'tenant_id' => fn (array $attributes) => User::find($attributes['user_id'])?->tenant_id
                ?? Tenant::factory(),
            'name' => ucwords($name),
            'slug' => Str::slug($name),
            'status' => 'active',
            'regenerate_rundowns_nightly' => false,
        ];
    }
}
