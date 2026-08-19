<?php

namespace Database\Factories;

use App\Enums\ChangeSafety;
use App\Enums\Sensitivity;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Variable>
 */
class VariableFactory extends Factory
{
    protected $model = Variable::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'key' => Str::upper(fake()->unique()->word()).'_KEY',
            'description' => fake()->sentence(),
            'sensitivity' => Sensitivity::Sensitive,
            'change_safety' => ChangeSafety::Safe,
            'created_by' => User::factory()->onboarded(),
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'sensitivity' => Sensitivity::Critical,
            'change_safety' => ChangeSafety::Breaking,
        ]);
    }

    public function publicish(): static
    {
        return $this->state(fn () => ['sensitivity' => Sensitivity::Public]);
    }
}
