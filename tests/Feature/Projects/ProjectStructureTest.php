<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\CreateGroup;
use App\Actions\Projects\DeleteEnvironment;
use App\Actions\Projects\DeleteGroup;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateGroup;
use App\Actions\Projects\UpdateProject;
use App\Actions\Projects\UpdateProjectSettings;
use App\Actions\Projects\UpdateRevealPolicy;
use App\Enums\Permission;
use App\Enums\RevealRequirement;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Everything that changes the shape of a project after it exists.
 *
 * All of it is administrative: a member with full access to prod still cannot
 * rename the project or loosen the reveal policy.
 */
class ProjectStructureTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private Project $project;

    private Environment $prod;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->organization->creator;
        $this->project = Project::factory()->for($this->organization)->create(['name' => 'API']);
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
    }

    private function member(): User
    {
        return $this->joinMember($this->organization);
    }

    private function events(): array
    {
        return DB::table('activity_log')->pluck('event')->all();
    }

    // ── The project itself ──────────────────────────────────────────────────

    public function test_an_owner_renames_a_project_and_the_slug_follows(): void
    {
        app(UpdateProject::class)($this->project, $this->owner, 'Billing API', 'Handles invoices');

        $project = $this->project->fresh();

        $this->assertSame('Billing API', $project->name);
        $this->assertSame('billing-api', $project->slug);
        $this->assertSame('Handles invoices', $project->description);
        $this->assertContains('project.updated', $this->events());
    }

    public function test_a_rename_cannot_collide_with_another_project_in_the_organization(): void
    {
        Project::factory()->for($this->organization)->create(['name' => 'Billing']);

        $this->expectException(ValidationException::class);

        app(UpdateProject::class)($this->project, $this->owner, 'Billing');
    }

    public function test_a_plain_member_cannot_rename_a_project_and_the_attempt_is_audited(): void
    {
        $member = $this->member();

        try {
            app(UpdateProject::class)($this->project, $member, 'Hijacked');
            $this->fail('A plain member renamed a project.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame('API', $this->project->fresh()->name);
        $this->assertContains('project.update-denied', $this->events());
    }

    public function test_deleting_a_project_is_reversible_and_audited(): void
    {
        app(DeleteProject::class)($this->project, $this->owner);

        $this->assertSoftDeleted('projects', ['id' => $this->project->id]);
        $this->assertContains('project.deleted', $this->events());
    }

    public function test_settings_update_on_the_project_allows_deleting_it_but_a_plain_member_may_not(): void
    {
        $manager = $this->member();
        $this->grant($manager, Permission::UpdateSettings, $this->project);

        app(DeleteProject::class)($this->project, $manager);
        $this->assertSoftDeleted('projects', ['id' => $this->project->id]);

        $bystander = $this->member();
        $other = Project::factory()->for($this->organization)->create();

        $this->expectException(AuthorizationException::class);

        app(DeleteProject::class)($other, $bystander);
    }

    // ── Environments ────────────────────────────────────────────────────────

    public function test_deleting_an_environment_takes_its_values_and_policies_with_it(): void
    {
        Environment::factory()->for($this->project)->create(['name' => 'dev']);

        $variable = Variable::factory()->for($this->project)->create(['key' => 'DATABASE_URL']);
        $variable->values()->create([
            'environment_id' => $this->prod->id,
            'value' => 'fake-value',
            'updated_by' => $this->owner->id,
        ]);

        app(DeleteEnvironment::class)($this->prod, $this->owner);

        $this->assertDatabaseMissing('environments', ['id' => $this->prod->id]);
        $this->assertDatabaseMissing('variable_values', ['environment_id' => $this->prod->id]);
        $this->assertDatabaseMissing('environment_reveal_policies', ['environment_id' => $this->prod->id]);
        $this->assertContains('environment.deleted', $this->events());
    }

    public function test_the_last_environment_cannot_be_deleted(): void
    {
        $this->expectException(ValidationException::class);

        app(DeleteEnvironment::class)($this->prod, $this->owner);
    }

    public function test_a_plain_member_cannot_delete_an_environment(): void
    {
        Environment::factory()->for($this->project)->create(['name' => 'dev']);

        $this->expectException(AuthorizationException::class);

        app(DeleteEnvironment::class)($this->prod, $this->member());
    }

    // ── Reveal policy ───────────────────────────────────────────────────────

    public function test_an_owner_changes_what_a_reveal_costs(): void
    {
        app(UpdateRevealPolicy::class)($this->prod, $this->owner, Sensitivity::Critical, RevealRequirement::Pin);

        $this->assertSame(
            RevealRequirement::Pin,
            $this->prod->fresh()->requirementFor(Sensitivity::Critical),
        );
        $this->assertContains('reveal-policy.updated', $this->events());
    }

    public function test_a_member_with_full_environment_access_still_cannot_loosen_the_policy(): void
    {
        $member = $this->member();
        $this->grantFullAccess($member, $this->prod);

        try {
            app(UpdateRevealPolicy::class)($this->prod, $member, Sensitivity::Critical, RevealRequirement::None);
            $this->fail('Full environment access loosened the reveal policy.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(
            RevealRequirement::PinAndPassword,
            $this->prod->fresh()->requirementFor(Sensitivity::Critical),
        );
        $this->assertContains('reveal-policy.update-denied', $this->events());
    }

    // ── Settings ────────────────────────────────────────────────────────────

    public function test_an_owner_tunes_the_project_settings(): void
    {
        app(UpdateProjectSettings::class)($this->project, $this->owner, [
            'audit_views' => false,
            'pin_max_attempts' => 3,
            'pin_lockout_minutes' => 30,
        ]);

        $settings = $this->project->fresh()->settings;

        $this->assertFalse($settings->audit_views);
        $this->assertSame(3, $settings->pin_max_attempts);
        $this->assertSame(30, $settings->pin_lockout_minutes);
        $this->assertContains('project-settings.updated', $this->events());
    }

    public function test_attempt_and_lockout_limits_must_stay_sane(): void
    {
        $this->expectException(ValidationException::class);

        app(UpdateProjectSettings::class)($this->project, $this->owner, ['pin_max_attempts' => 0]);
    }

    /** Turning off view auditing must never touch the auditing of changes. */
    public function test_disabling_view_auditing_still_records_the_change_itself(): void
    {
        app(UpdateProjectSettings::class)($this->project, $this->owner, ['audit_views' => false]);

        $this->assertContains('project-settings.updated', $this->events());
    }

    // ── Groups ──────────────────────────────────────────────────────────────

    public function test_groups_can_be_created_renamed_and_deleted(): void
    {
        $group = app(CreateGroup::class)($this->project, $this->owner, 'Database');
        $this->assertSame('database', $group->slug);

        app(UpdateGroup::class)($group, $this->owner, 'Datastore', 2);
        $this->assertSame('Datastore', $group->fresh()->name);
        $this->assertSame(2, $group->fresh()->position);

        app(DeleteGroup::class)($group, $this->owner);
        $this->assertDatabaseMissing('groups', ['id' => $group->id]);

        foreach (['group.created', 'group.updated', 'group.deleted'] as $event) {
            $this->assertContains($event, $this->events());
        }
    }

    public function test_deleting_a_group_leaves_its_variables_ungrouped_rather_than_deleting_them(): void
    {
        $group = app(CreateGroup::class)($this->project, $this->owner, 'Database');
        $variable = Variable::factory()->for($this->project)->create([
            'key' => 'DATABASE_URL',
            'group_id' => $group->id,
        ]);

        app(DeleteGroup::class)($group, $this->owner);

        $this->assertDatabaseHas('variables', ['id' => $variable->id]);
        $this->assertNull($variable->fresh()->group_id);
    }

    public function test_two_groups_in_one_project_cannot_share_a_name(): void
    {
        app(CreateGroup::class)($this->project, $this->owner, 'Database');

        $this->expectException(ValidationException::class);

        app(CreateGroup::class)($this->project, $this->owner, 'Database');
    }

    public function test_sibling_projects_may_each_have_a_group_of_the_same_name(): void
    {
        $other = Project::factory()->for($this->organization)->create();

        app(CreateGroup::class)($this->project, $this->owner, 'Database');
        $group = app(CreateGroup::class)($other, $this->owner, 'Database');

        $this->assertSame('database', $group->slug);
    }

    public function test_a_plain_member_cannot_create_a_group(): void
    {
        $this->expectException(AuthorizationException::class);

        app(CreateGroup::class)($this->project, $this->member(), 'Database');
    }

    public function test_nothing_here_reaches_across_organizations(): void
    {
        $outsider = User::factory()->onboarded()->create();
        $otherOrganization = Organization::factory()->create();
        $this->fullManager($otherOrganization, $outsider);

        $this->expectException(AuthorizationException::class);

        app(UpdateProject::class)($this->project, $outsider, 'Taken over');
    }
}
