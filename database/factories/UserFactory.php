<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Totp;
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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'theme' => 'auto',
            'remember_token' => Str::random(10),
        ];
    }

    /** An account that has been through the whole authenticator setup. */
    public function withAuthenticator(?string $secret = null): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => $secret ?? Totp::secret(),
            'two_factor_recovery_codes' => User::newRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function staff(): static
    {
        return $this->state(fn (array $attributes) => ['role' => User::ROLE_STAFF]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
