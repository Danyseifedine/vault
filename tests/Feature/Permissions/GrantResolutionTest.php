<?php

namespace Tests\Feature\Permissions;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * How a grant row resolves into an answer. The one rule under test: a grant
 * covers everything beneath its scope - including things created later - and
 * nothing beside it. The default answer is always no.
 */
class GrantResolutionTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private Project $project;

    private Environment $dev;

    private Environment $prod;

    private User $member;

    private AccessResolver $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->for($this->organization)->create();
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
        $this->member = $this->joinMember($this->organization);
        $this->access = app(AccessResolver::class);
    }

    public function test_a_joined_member_with_no_grants_can_do_nothing(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertFalse(
                $this->access->can($this->member, $permission, $this->organization),
                $permission->value,
            );
        }

        $this->assertFalse($this->access->can($this->member, Permission::ViewVariables, $this->dev));
        $this->assertFalse($this->access->canAccessProject($this->member, $this->project));
    }

    public function test_an_environment_grant_covers_that_environment_alone(): void
    {
        $this->grant($this->member, Permission::ViewVariables, $this->dev);

        $this->assertTrue($this->access->can($this->member, Permission::ViewVariables, $this->dev));
        $this->assertFalse($this->access->can($this->member, Permission::ViewVariables, $this->prod));
        // And only that action - not its neighbours.
        $this->assertFalse($this->access->can($this->member, Permission::RevealValues, $this->dev));
    }

    public function test_a_project_grant_covers_environments_created_afterwards(): void
    {
        $this->grant($this->member, Permission::ViewVariables, $this->project);

        $staging = Environment::factory()->for($this->project)->create(['name' => 'staging']);

        $this->assertTrue($this->access->can($this->member, Permission::ViewVariables, $this->dev));
        $this->assertTrue($this->access->can($this->member, Permission::ViewVariables, $staging));
    }

    public function test_an_organization_grant_covers_projects_created_afterwards(): void
    {
        $this->grant($this->member, Permission::ViewVariables, $this->organization);

        $later = Project::factory()->for($this->organization)->create();
        $laterEnv = Environment::factory()->for($later)->create(['name' => 'prod']);

        $this->assertTrue($this->access->can($this->member, Permission::ViewVariables, $laterEnv));
        $this->assertTrue($this->access->canAccessProject($this->member, $later));
    }

    public function test_a_grant_in_one_project_reaches_nothing_in_a_sibling(): void
    {
        $sibling = Project::factory()->for($this->organization)->create();
        $siblingEnv = Environment::factory()->for($sibling)->create(['name' => 'prod']);

        $this->grant($this->member, Permission::UpdateSettings, $this->project);
        $this->grant($this->member, Permission::ViewVariables, $this->dev);

        $this->assertFalse($this->access->can($this->member, Permission::UpdateSettings, $sibling));
        $this->assertFalse($this->access->can($this->member, Permission::ViewVariables, $siblingEnv));
        $this->assertFalse($this->access->canAccessProject($this->member, $sibling));
    }

    public function test_membership_is_required_even_when_a_stray_grant_row_exists(): void
    {
        $stranger = User::factory()->onboarded()->create();

        // A grant row without membership - written around the guards somehow -
        // must still answer no: the chain starts at belonging.
        Grant::create([
            'user_id' => $stranger->id,
            'organization_id' => $this->organization->id,
            'permission' => Permission::ViewVariables->value,
        ]);

        $this->assertFalse($this->access->can($stranger, Permission::ViewVariables, $this->dev));
        $this->assertFalse($this->access->canAccessProject($stranger, $this->project));
    }

    public function test_nothing_leaks_across_organizations(): void
    {
        $other = Organization::factory()->create();
        $otherProject = Project::factory()->for($other)->create();
        $otherEnv = Environment::factory()->for($otherProject)->create(['name' => 'prod']);

        $this->grant($this->member, Permission::cases(), $this->organization);

        $this->assertFalse($this->access->can($this->member, Permission::ViewVariables, $otherEnv));
        $this->assertFalse($this->access->canAccessProject($this->member, $otherProject));
    }

    public function test_org_native_permissions_resolve_at_the_organization(): void
    {
        $this->grant($this->member, Permission::ManagePins, $this->organization);

        $this->assertTrue($this->access->can($this->member, Permission::ManagePins, $this->organization));
        $this->assertFalse($this->access->can($this->member, Permission::ManageMembers, $this->organization));
    }

    public function test_opening_a_project_takes_a_grant_that_means_something_there(): void
    {
        // Org-native powers do not open project pages: inviting people is not
        // a reason to read the billing API's variable list.
        $this->grant($this->member, Permission::InviteMembers, $this->organization);
        $this->assertFalse($this->access->canAccessProject($this->member, $this->project));

        // A project-native permission org-wide does.
        $manager = $this->joinMember($this->organization);
        $this->grant($manager, Permission::UpdateSettings, $this->organization);
        $this->assertTrue($this->access->canAccessProject($manager, $this->project));

        // And so does a single environment action inside it.
        $reader = $this->joinMember($this->organization);
        $this->grant($reader, Permission::ViewVariables, $this->dev);
        $this->assertTrue($this->access->canAccessProject($reader, $this->project));
    }

    public function test_seeing_an_environment_follows_the_same_logic(): void
    {
        $this->grant($this->member, Permission::ViewVariables, $this->dev);

        $this->assertTrue($this->access->canSeeEnvironment($this->member, $this->dev));
        $this->assertFalse($this->access->canSeeEnvironment($this->member, $this->prod));

        // Project-shape powers see every environment of their project.
        $shaper = $this->joinMember($this->organization);
        $this->grant($shaper, Permission::ManageEnvironments, $this->project);
        $this->assertTrue($this->access->canSeeEnvironment($shaper, $this->prod));

        // Org-native powers do not.
        $inviter = $this->joinMember($this->organization);
        $this->grant($inviter, Permission::InviteMembers, $this->organization);
        $this->assertFalse($this->access->canSeeEnvironment($inviter, $this->prod));
    }

    public function test_holds_somewhere_in_finds_a_grant_at_any_depth(): void
    {
        $this->assertFalse(
            $this->access->holdsSomewhereIn($this->member, Permission::UpdateVariables, $this->project),
        );

        $this->grant($this->member, Permission::UpdateVariables, $this->dev);

        $this->assertTrue(
            $this->access->holdsSomewhereIn($this->member, Permission::UpdateVariables, $this->project),
        );

        $sibling = Project::factory()->for($this->organization)->create();

        $this->assertFalse(
            $this->access->holdsSomewhereIn($this->member, Permission::UpdateVariables, $sibling),
        );
    }
}
