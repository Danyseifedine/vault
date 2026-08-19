<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'created_by' => User::factory()->onboarded(),
        ];
    }

    /**
     * Attach the creator as a member holding every permission org-wide - the
     * way CreateOrganization seeds it. Ordinary grant rows, revocable later
     * like anyone else's.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization) {
            $organization->members()->attach($organization->created_by, [
                'joined_at' => now(),
            ]);

            foreach (Permission::cases() as $permission) {
                Grant::create([
                    'user_id' => $organization->created_by,
                    'organization_id' => $organization->id,
                    'permission' => $permission->value,
                    'created_by' => $organization->created_by,
                ]);
            }
        });
    }
}
