<?php

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\CreateOrganization;
use App\Enums\Permission;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Starting an organization is the one power that cannot be a grant: a grant
 * row always belongs to an organization, and the organization being created
 * does not exist yet. It is an account-level capability instead, handed out
 * from the command line where server access is already required.
 */
class CreateOrganizationPrivilegeTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private function allowedUser(): User
    {
        return User::factory()->onboarded()->organizationCreator()->create();
    }

    public function test_an_allowed_account_creates_an_organization_and_holds_everything_in_it(): void
    {
        $user = $this->allowedUser();

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => 'Lebify'])
            ->assertRedirect();

        $organization = Organization::query()->sole();

        $this->assertTrue($organization->hasMember($user));
        $this->assertSame(
            count(Permission::cases()),
            Grant::where('user_id', $user->id)->count(),
        );
    }

    public function test_an_ordinary_account_is_refused_and_the_attempt_is_recorded(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => 'Shadow Co'])
            ->assertForbidden();

        $this->assertSame(0, Organization::query()->count());
        $this->assertDatabaseHas('activity_log', [
            'event' => 'organization.create-denied',
            'causer_id' => $user->id,
        ]);
    }

    /**
     * The bug this rule exists for: holding every organization permission
     * INSIDE one organization must not let someone start another one, where
     * nobody could reach them.
     */
    public function test_holding_rename_and_delete_inside_an_organization_confers_nothing(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->joinMember($organization);

        $this->grant($member, [
            Permission::UpdateOrganization,
            Permission::DeleteOrganization,
            Permission::ManageMembers,
        ], $organization);

        $this->actingAs($member)
            ->post(route('organizations.store'), ['name' => 'Second Co'])
            ->assertForbidden();

        $this->assertSame(1, Organization::query()->count());
    }

    public function test_the_action_refuses_on_its_own_not_only_at_the_route(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->expectException(AuthorizationException::class);

        app(CreateOrganization::class)($user, 'Bypass Co');
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->post(route('organizations.store'), ['name' => 'Lebify'])
            ->assertRedirect(route('login'));
    }

    public function test_the_dashboard_says_whether_the_viewer_may_create_one(): void
    {
        $this->actingAs($this->allowedUser())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('canCreateOrganizations', true));

        $this->actingAs(User::factory()->onboarded()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('canCreateOrganizations', false));
    }

    public function test_the_command_grants_and_revokes_the_capability(): void
    {
        $user = User::factory()->onboarded()->create(['email' => 'dev@lebify.test']);

        $this->artisan('vault:allow-organizations', ['email' => 'dev@lebify.test'])
            ->assertExitCode(0);

        $this->assertTrue($user->fresh()->can_create_organizations);

        $this->artisan('vault:allow-organizations', [
            'email' => 'dev@lebify.test',
            '--revoke' => true,
        ])->assertExitCode(0);

        $this->assertFalse($user->fresh()->can_create_organizations);
    }

    public function test_the_command_refuses_an_unknown_address(): void
    {
        $this->artisan('vault:allow-organizations', ['email' => 'nobody@lebify.test'])
            ->assertExitCode(1);
    }
}
