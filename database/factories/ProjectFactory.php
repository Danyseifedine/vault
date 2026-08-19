<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSettings;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'created_by' => User::factory()->onboarded(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Project $project) {
            ProjectSettings::firstOrCreate(['project_id' => $project->id]);
        });
    }
}
