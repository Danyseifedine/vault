<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            // No recovery codes, here or anywhere: the authenticator app is the
            // only second factor The Vault accepts.
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * A user who has finished every onboarding step and can actually use the
     * application - what most feature tests need.
     */
    public function onboarded(): static
    {
        return $this->withTwoFactor()->state(fn (array $attributes) => [
            'status' => UserStatus::Active,
            'job_title' => fake()->jobTitle(),
            'stack' => ['Laravel', 'React'],
            'profile_completed_at' => now(),
        ]);
    }

    /**
     * An account allowed to start organizations - a system-level capability,
     * not something any organization can grant.
     */
    public function organizationCreator(): static
    {
        return $this->state(fn (array $attributes) => [
            'can_create_organizations' => true,
        ]);
    }

    /**
     * A seat reserved by an invitation: no password, no name, nothing claimed.
     */
    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => null,
            'password' => null,
            'status' => UserStatus::Invited,
            'email_verified_at' => null,
            'profile_completed_at' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
