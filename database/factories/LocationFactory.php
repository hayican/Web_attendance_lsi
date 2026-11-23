<?php

namespace Database\Factories;

use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('LOC-###')),
            'name' => fake()->company(),
            'type' => LocationType::OFFICE,
            'address' => fake()->address(),
            'latitude' => -6.2000000,
            'longitude' => 106.8166667,
            'radius_m' => 100,
            'timezone' => 'Asia/Jakarta',
            'work_days' => [1, 2, 3, 4, 5],
            'status' => LocationStatus::ACTIVE,
            'reject_reason' => null,
            'created_by' => null,
        ];
    }
}
