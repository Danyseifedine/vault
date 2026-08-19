<?php

namespace Database\Factories;

use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    protected $model = Environment::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->word(),
            'position' => 0,
        ];
    }

    /** Environments are useless without their reveal matrix. */
    public function configure(): static
    {
        return $this->afterCreating(function (Environment $environment) {
            foreach (Sensitivity::cases() as $sensitivity) {
                $environment->revealPolicies()->firstOrCreate(
                    ['sensitivity' => $sensitivity],
                    ['requirement' => $sensitivity->defaultRequirement()],
                );
            }
        });
    }
}
