<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nik' => fake()->unique()->numerify('LSI######'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'must_change_password' => false,
            'role' => UserRole::EMPLOYEE_OFFICE,
            'office_location_id' => Location::factory(),
            'default_shift_pattern_id' => null,
            'join_date' => now()->subYears(2)->toDateString(),
            'reference_photo_url' => null,
            'consent_accepted_at' => now(),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
