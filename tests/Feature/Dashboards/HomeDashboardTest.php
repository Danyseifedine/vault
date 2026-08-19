<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Invitations\SendInvitation;
use App\Actions\PersonalVault\CreatePersonalSecret;
use App\Actions\Pins\IssuePin;
use App\Actions\Projects\CreateGroup;
use App\Actions\Tags\CreateTag;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Permission;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The props each page actually receives, now that the frontend consumes them.
 *
 * These are contract tests: the React pages index into `grants` by email and
 * address variables by id, so a payload missing either silently breaks a screen.
 */
class HomeDashboardTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Project $project;

    private Environment $prod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->organization->creator;
        $this->project = Project::factory()->for($this->organization)->create(['name' => 'API']);
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
    }

    public function test_a_guest_cannot_see_the_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_it_lists_only_the_organizations_you_belong_to(): void
    {
        Organization::factory()->create(['name' => 'Someone Else']);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard/index')
                ->has('organizations', 1)
                ->where('organizations.0.slug', $this->organization->slug)
                ->where('organizations.0.canUpdate', true)
                ->where('organizations.0.canDelete', true)
                ->where('organizations.0.projects', 1));
    }

    public function test_someone_in_no_organization_sees_an_empty_list(): void
    {
        $stranger = User::factory()->onboarded()->create();

        $this->actingAs($stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('organizations', 0));
    }

    // ── Organization page contract ──────────────────────────────────────────

    public function test_the_organization_page_sends_scopes_the_invite_dialog_can_grant_against(): void
    {
        $this->actingAs($this->owner)
            ->get(route('organizations.show', $this->organization))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scopes.0.slug', $this->project->slug)
                ->where('scopes.0.environments.0.id', $this->prod->id)
                ->where('scopes.0.environments.0.slug', 'prod')
                ->etc());
    }

    public function test_the_organization_page_says_what_the_viewer_may_do(): void
    {
        $member = $this->joinMember($this->organization);
        $this->grantReader($member, $this->prod);

        $this->actingAs($this->owner)
            ->get(route('organizations.show', $this->organization))
            ->assertInertia(fn ($page) => $page
                ->where('organization.canInvite', true)
                ->where('organization.canCreateProjects', true)
                ->etc());

        $this->actingAs($member)
            ->get(route('organizations.show', $this->organization))
            ->assertInertia(fn ($page) => $page
                ->where('organization.canInvite', false)
                ->where('organization.canCreateProjects', false)
                ->etc());
    }

    public function test_pending_invites_carry_the_id_the_revoke_button_needs(): void
    {
        Mail::fake();

        $invitation = app(SendInvitation::class)(
            $this->organization, $this->owner, 'newcomer@lebify.test',
        );

        $this->actingAs($this->owner)
            ->get(route('organizations.show', $this->organization))
            ->assertInertia(fn ($page) => $page
                ->where('invites.0.id', $invitation->id)
                ->where('invites.0.email', 'newcomer@lebify.test')
                ->etc());
    }

    // ── Project page contract ───────────────────────────────────────────────

    public function test_variables_carry_the_id_the_reveal_endpoint_addresses(): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');
        app(SetVariableValue::class)($variable, $this->prod, $this->owner, 'postgres://fake');

        $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('variables.0.id', $variable->id)
                ->where('variables.0.key', 'DATABASE_URL')
                ->etc());
    }

    public function test_the_permissions_matrix_is_keyed_by_member_and_reflects_grants(): void
    {
        $reader = $this->joinMember($this->organization);
        $this->grantReader($reader, $this->prod);

        $actions = Permission::environmentActions();
        $updateIndex = array_search(Permission::UpdateVariables, $actions, strict: true);

        // Read the props directly: emails contain dots, which Inertia's
        // assertion helper would read as a nested path.
        $props = fn (): array => $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->viewData('page')['props'];

        $before = $props();

        $this->assertCount(count($actions), $before['permissions']);
        // One environment, so the row is one cell per permission, in order.
        $this->assertSame(0, $before['grants'][$reader->email][$updateIndex]);

        $this->grant($reader, Permission::UpdateVariables, $this->prod);

        $this->assertSame(3, $props()['grants'][$reader->email][$updateIndex]);
    }

    public function test_the_project_page_sends_what_the_management_screens_need(): void
    {
        $group = app(CreateGroup::class)($this->project, $this->owner, 'Database');

        $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('admin.can.updateSettings', true)
                ->where('admin.settings.pinMaxAttempts', 5)
                ->where('admin.environments.0.slug', 'prod')
                // The reveal matrix, defaulting closed for critical values.
                ->where('admin.environments.0.policies.critical', 'pin_password')
                ->where('admin.groups.0.name', $group->name)
                ->has('admin.permissionKeys')
                ->etc());
    }

    public function test_a_member_is_told_they_cannot_manage_the_project(): void
    {
        $member = $this->joinMember($this->organization);
        $this->grantFullAccess($member, $this->prod);

        $this->actingAs($member)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertInertia(fn ($page) => $page
                ->where('admin.can.updateSettings', false)
                ->where('admin.can.manageMembers', false)
                ->etc());
    }

    public function test_the_project_page_lists_people_with_their_grants_per_environment(): void
    {
        $reader = $this->joinMember($this->organization);
        $this->grantReader($reader, $this->prod);

        $props = $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->viewData('page')['props'];

        $person = collect($props['admin']['people'])->firstWhere('email', $reader->email);

        $this->assertNotNull($person);
        $this->assertContains(
            Permission::ViewVariables->value,
            $person['grants']['environments'][$this->prod->id],
        );
        $this->assertSame([], $person['grants']['organization']);
    }

    // ── Personal vault ──────────────────────────────────────────────────────

    public function test_the_personal_vault_lists_metadata_but_never_a_value(): void
    {
        app(CreatePersonalSecret::class)($this->owner, 'GitHub token', 'ghp_fake_123');

        $response = $this->actingAs($this->owner)->get(route('personal.index'));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('personal/index')
            ->has('items', 1)
            ->where('items.0.name', 'GitHub token')
            ->where('items.0.type', 'secret')
            ->etc());

        $this->assertStringNotContainsString('ghp_fake_123', (string) $response->getContent());
    }

    public function test_the_personal_vault_shows_only_your_own_items(): void
    {
        $stranger = User::factory()->onboarded()->create();

        app(CreatePersonalSecret::class)($this->owner, 'Mine', 'fake');
        app(CreatePersonalSecret::class)($stranger, 'Theirs', 'fake');

        $this->actingAs($this->owner)
            ->get(route('personal.index'))
            ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.name', 'Mine')->etc());
    }

    // ── Organization page contract, continued ───────────────────────────────

    public function test_the_organization_page_sends_tags_and_pin_state(): void
    {
        app(CreateTag::class)->global($this->organization, $this->owner, 'rotate-soon');
        app(IssuePin::class)($this->organization, $this->owner, $this->owner, '1234', 'laptop');

        $this->actingAs($this->owner)
            ->get(route('organizations.show', $this->organization))
            ->assertInertia(fn ($page) => $page
                ->where('tags.0.name', 'rotate-soon')
                ->where('tags.0.scope', 'organization')
                ->where('organization.canManagePins', true)
                ->where('members.0.pins.0.label', 'laptop')
                ->where('members.0.pins.0.status', 'active')
                ->etc());
    }

    public function test_a_member_manager_without_the_pin_grant_is_not_offered_pin_controls(): void
    {
        $manager = $this->joinMember($this->organization);
        $this->grant($manager, Permission::ManageMembers, $this->organization);

        $this->actingAs($manager)
            ->get(route('organizations.show', $this->organization))
            ->assertInertia(fn ($page) => $page
                ->where('organization.canManagePins', false)
                ->where('organization.canManageMembers', true)
                ->where('organization.canCreateGlobalTags', false)
                ->etc());
    }

    /** The masking rule the whole product rests on, asserted at the page level. */
    public function test_no_page_prop_ever_carries_a_masked_value(): void
    {
        $critical = app(CreateVariable::class)(
            $this->project, $this->owner, 'DATABASE_URL', sensitivity: Sensitivity::Critical,
        );
        app(SetVariableValue::class)($critical, $this->prod, $this->owner, 'postgres://super-secret');

        $body = (string) $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->getContent();

        $this->assertStringNotContainsString('postgres://super-secret', $body);
    }
}
