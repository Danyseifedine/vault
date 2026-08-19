<?php

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Projects\CreateProject;
use App\Enums\Permission;
use App\Enums\RevealRequirement;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_creator_starts_joined_and_holding_every_permission(): void
    {
        $user = User::factory()->onboarded()->organizationCreator()->create();

        $organization = app(CreateOrganization::class)($user, 'Acme Inc.');

        $this->assertSame('acme-inc', $organization->slug);
        $this->assertTrue($organization->hasMember($user));

        // One org-wide row per permission - the creator's power is ordinary
        // grants, not a special role baked in somewhere.
        $this->assertSame(
            count(Permission::cases()),
            Grant::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->whereNull('project_id')
                ->whereNull('environment_id')
                ->count(),
        );

        $this->assertDatabaseHas('activity_log', [
            'event' => 'organization.created',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_a_stranger_is_not_a_member_and_holds_nothing(): void
    {
        $organization = Organization::factory()->create();
        $stranger = User::factory()->onboarded()->create();

        $this->assertFalse($organization->hasMember($stranger));
        $this->assertSame(0, Grant::query()->where('user_id', $stranger->id)->count());
    }

    public function test_creating_a_project_seeds_settings_and_environments(): void
    {
        $owner = User::factory()->onboarded()->organizationCreator()->create();
        $organization = app(CreateOrganization::class)($owner, 'Acme Inc.');

        $project = app(CreateProject::class)($organization, $owner, 'lebify');

        $this->assertSame('lebify', $project->slug);
        $this->assertNotNull($project->settings);
        $this->assertTrue($project->settings->audit_views);
        $this->assertSame(5, $project->settings->pin_max_attempts);
        $this->assertEqualsCanonicalizing(
            ['dev', 'staging', 'prod'],
            $project->environments->pluck('slug')->all(),
        );
    }

    public function test_every_new_environment_starts_with_a_safe_reveal_matrix(): void
    {
        $owner = User::factory()->onboarded()->organizationCreator()->create();
        $organization = app(CreateOrganization::class)($owner, 'Acme Inc.');
        $project = app(CreateProject::class)($organization, $owner, 'lebify');

        $prod = $project->environments()->where('slug', 'prod')->first();

        $this->assertSame(RevealRequirement::PinAndPassword, $prod->requirementFor(Sensitivity::Critical));
        $this->assertSame(RevealRequirement::Pin, $prod->requirementFor(Sensitivity::Sensitive));
        $this->assertSame(RevealRequirement::None, $prod->requirementFor(Sensitivity::Public));
    }

    public function test_a_missing_policy_row_never_means_a_free_reveal(): void
    {
        $environment = Environment::factory()->create();
        $environment->revealPolicies()->delete();
        $environment->refresh()->load('revealPolicies');

        // Fails closed: the safe default, not "no requirement".
        $this->assertSame(
            RevealRequirement::PinAndPassword,
            $environment->requirementFor(Sensitivity::Critical),
        );
    }

    public function test_project_slugs_are_unique_within_an_organization_only(): void
    {
        $owner = User::factory()->onboarded()->organizationCreator()->create();
        $first = app(CreateOrganization::class)($owner, 'Acme Inc.');
        $second = app(CreateOrganization::class)($owner, 'Acme Labs');

        app(CreateProject::class)($first, $owner, 'shared-name');
        $other = app(CreateProject::class)($second, $owner, 'shared-name');

        // The same project name may exist in a different organization.
        $this->assertSame('shared-name', $other->slug);
        $this->assertDatabaseCount('projects', 2);
    }
}
