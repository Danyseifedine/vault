<?php

namespace Tests\Feature\Http;

use App\Actions\Variables\CreateVariable;
use App\Enums\Permission;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Models\Variable;
use App\Services\Access\AccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The routes that shape a project. Guests are turned away everywhere, members
 * are held to their grants, and the URL cannot be used to cross a boundary.
 */
class StructureEndpointsTest extends TestCase
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

    // ── Guests ──────────────────────────────────────────────────────────────

    public function test_every_mutating_route_turns_a_guest_away(): void
    {
        $routes = [
            ['post', route('projects.store', $this->organization)],
            ['patch', route('projects.update', [$this->organization, $this->project])],
            ['delete', route('projects.destroy', [$this->organization, $this->project])],
            ['post', route('groups.store', [$this->organization, $this->project])],
            ['post', route('variables.store', [$this->organization, $this->project])],
            ['put', route('projects.settings', [$this->organization, $this->project])],
        ];

        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }
    }

    // ── Organizations & projects ────────────────────────────────────────────

    public function test_a_signed_in_user_creates_an_organization_and_starts_with_every_permission(): void
    {
        $user = User::factory()->onboarded()->organizationCreator()->create();

        $response = $this->actingAs($user)->post(route('organizations.store'), ['name' => 'Lebify']);

        $organization = Organization::query()->where('name', 'Lebify')->firstOrFail();

        $response->assertRedirect(route('organizations.show', $organization));

        $this->assertTrue($organization->hasMember($user));
        $this->assertSame(
            count(Permission::cases()),
            Grant::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->whereNull('project_id')
                ->count(),
        );
    }

    public function test_an_owner_creates_a_project_and_can_immediately_reach_its_environments(): void
    {
        $this->actingAs($this->owner)
            ->post(route('projects.store', $this->organization), ['name' => 'Billing'])
            ->assertRedirect();

        $project = $this->organization->projects()->where('name', 'Billing')->firstOrFail();

        $this->assertCount(3, $project->environments);

        // Org-wide grants cascade into new environments; no per-environment
        // rows are written for the creator.
        $this->assertSame(0, Grant::query()->whereNotNull('environment_id')->count());
        $this->assertTrue(
            app(AccessResolver::class)->can($this->owner, Permission::ViewVariables, $project->environments->first()),
        );
    }

    public function test_a_member_without_projects_create_cannot_create_a_project(): void
    {
        $member = $this->joinMember($this->organization);

        // environments.manage on an existing project is not projects.create.
        $this->grant($member, Permission::ManageEnvironments, $this->project);

        $this->actingAs($member)
            ->post(route('projects.store', $this->organization), ['name' => 'Billing'])
            ->assertForbidden();
    }

    public function test_a_project_name_is_required(): void
    {
        $this->actingAs($this->owner)
            ->post(route('projects.store', $this->organization), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_a_project_cannot_be_reached_through_the_wrong_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        $this->fullManager($otherOrganization, $this->owner);

        $this->actingAs($this->owner)
            ->get(route('projects.show', [$otherOrganization, $this->project]))
            ->assertNotFound();
    }

    public function test_an_owner_renames_and_then_deletes_a_project(): void
    {
        $this->actingAs($this->owner)
            ->patch(route('projects.update', [$this->organization, $this->project]), ['name' => 'Billing API'])
            ->assertRedirect();

        // The slug follows the name, so the old URL is genuinely gone.
        $renamed = $this->project->fresh();
        $this->assertSame('Billing API', $renamed->name);
        $this->assertSame('billing-api', $renamed->slug);

        $this->actingAs($this->owner)
            ->delete(route('projects.destroy', [$this->organization, $renamed]))
            ->assertRedirect(route('organizations.show', $this->organization));

        $this->assertSoftDeleted('projects', ['id' => $this->project->id]);
    }

    // ── Settings & reveal policy ────────────────────────────────────────────

    public function test_settings_are_validated_and_saved(): void
    {
        $this->actingAs($this->owner)
            ->put(route('projects.settings', [$this->organization, $this->project]), ['pin_max_attempts' => 0])
            ->assertSessionHasErrors('pin_max_attempts');

        $this->actingAs($this->owner)
            ->put(route('projects.settings', [$this->organization, $this->project]), ['pin_max_attempts' => 3])
            ->assertRedirect();

        $this->assertSame(3, $this->project->fresh()->settings->pin_max_attempts);
    }

    public function test_a_member_with_full_access_cannot_touch_the_reveal_policy(): void
    {
        $member = $this->joinMember($this->organization);
        $this->grantFullAccess($member, $this->prod);

        $this->actingAs($member)
            ->put(route('reveal-policy.update', [$this->organization, $this->project, $this->prod]), [
                'sensitivity' => 'critical',
                'requirement' => 'none',
            ])
            ->assertForbidden();
    }

    public function test_an_owner_changes_the_reveal_policy(): void
    {
        $this->actingAs($this->owner)
            ->put(route('reveal-policy.update', [$this->organization, $this->project, $this->prod]), [
                'sensitivity' => 'critical',
                'requirement' => 'pin',
            ])
            ->assertRedirect();

        $this->assertSame('pin', $this->prod->fresh()->requirementFor(Sensitivity::Critical)->value);
    }

    // ── Groups and environments ─────────────────────────────────────────────

    public function test_groups_round_trip_through_their_routes(): void
    {
        $this->actingAs($this->owner)
            ->post(route('groups.store', [$this->organization, $this->project]), ['name' => 'Database'])
            ->assertRedirect();

        $group = Group::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->patch(route('groups.update', [$this->organization, $this->project, $group]), ['name' => 'Datastore'])
            ->assertRedirect();

        $this->assertSame('Datastore', $group->fresh()->name);

        $this->actingAs($this->owner)
            ->delete(route('groups.destroy', [$this->organization, $this->project, $group->fresh()]))
            ->assertRedirect();

        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
    }

    public function test_a_group_from_another_project_is_not_reachable(): void
    {
        $otherProject = Project::factory()->for($this->organization)->create();
        $foreignGroup = Group::query()->create(['project_id' => $otherProject->id, 'name' => 'Theirs']);

        $this->actingAs($this->owner)
            ->patch(route('groups.update', [$this->organization, $this->project, $foreignGroup]), ['name' => 'Mine'])
            ->assertNotFound();
    }

    public function test_the_last_environment_cannot_be_deleted_through_the_route(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('environments.destroy', [$this->organization, $this->project, $this->prod]))
            ->assertSessionHasErrors('environment');
    }

    // ── Variables ───────────────────────────────────────────────────────────

    public function test_a_writer_creates_a_variable_and_a_reader_cannot(): void
    {
        $writer = $this->joinMember($this->organization);
        $this->grantWriter($writer, $this->prod);
        $reader = $this->joinMember($this->organization);
        $this->grantReader($reader, $this->prod);

        $this->actingAs($writer)
            ->post(route('variables.store', [$this->organization, $this->project]), ['key' => 'DATABASE_URL'])
            ->assertRedirect();

        $this->assertDatabaseHas('variables', ['key' => 'DATABASE_URL']);

        $this->actingAs($reader)
            ->post(route('variables.store', [$this->organization, $this->project]), ['key' => 'OTHER_KEY'])
            ->assertForbidden();
    }

    public function test_a_variable_key_must_look_like_an_env_key(): void
    {
        $this->actingAs($this->owner)
            ->post(route('variables.store', [$this->organization, $this->project]), ['key' => 'not a key'])
            ->assertSessionHasErrors('key');
    }

    public function test_setting_a_value_needs_write_access_in_that_environment(): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');
        $reader = $this->joinMember($this->organization);
        $this->grantReader($reader, $this->prod);

        $this->actingAs($reader)
            ->put(route('values.update', [$this->organization, $this->project, $variable, $this->prod]), [
                'value' => 'postgres://fake',
            ])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->put(route('values.update', [$this->organization, $this->project, $variable, $this->prod]), [
                'value' => 'postgres://fake',
            ])
            ->assertRedirect();

        $this->assertSame('postgres://fake', $variable->fresh()->valueIn($this->prod)?->value);
    }

    /**
     * The variable and the environment are siblings in the URL, so nothing
     * scopes them for us implicitly - the explicit route binders resolve both
     * inside the project instead. A slug that only exists in another project
     * must therefore be unreachable from here.
     */
    public function test_an_environment_from_another_project_is_unreachable(): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');

        $otherProject = Project::factory()->for($this->organization)->create();
        $foreignEnvironment = Environment::factory()->for($otherProject)->create(['name' => 'staging']);

        $this->actingAs($this->owner)
            ->put(route('values.update', [$this->organization, $this->project, $variable, $foreignEnvironment]), [
                'value' => 'leaked',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('variable_values', 0);
    }

    /** The same guarantee for the variable half of that pair. */
    public function test_a_variable_from_another_project_is_unreachable(): void
    {
        $otherProject = Project::factory()->for($this->organization)->create();
        $foreignVariable = app(CreateVariable::class)($otherProject, $this->owner, 'THEIR_SECRET');

        $this->actingAs($this->owner)
            ->put(route('values.update', [$this->organization, $this->project, $foreignVariable, $this->prod]), [
                'value' => 'leaked',
            ])
            ->assertNotFound();
    }

    /** And for a project reached through an organization that does not own it. */
    public function test_a_project_from_another_organization_is_unreachable(): void
    {
        $otherOrganization = Organization::factory()->create();
        $this->fullManager($otherOrganization, $this->owner);
        $foreignProject = Project::factory()->for($otherOrganization)->create(['name' => 'Theirs']);

        $this->actingAs($this->owner)
            ->post(route('variables.store', [$this->organization, $foreignProject]), ['key' => 'LEAKED'])
            ->assertNotFound();
    }

    public function test_deleting_a_variable_needs_delete_rights_everywhere_it_lives(): void
    {
        $dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
        $member = $this->joinMember($this->organization);
        $this->grantFullAccess($member, $this->prod);
        $this->grantReader($member, $dev);

        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');
        $variable->values()->create([
            'environment_id' => $dev->id,
            'value' => 'x',
            'updated_by' => $this->owner->id,
        ]);

        // Full in prod, only read in dev - and the variable lives in dev.
        $this->actingAs($member)
            ->delete(route('variables.destroy', [$this->organization, $this->project, $variable]))
            ->assertForbidden();
    }

    public function test_a_variable_index_response_never_carries_a_value(): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');
        $variable->values()->create([
            'environment_id' => $this->prod->id,
            'value' => 'postgres://super-secret',
            'updated_by' => $this->owner->id,
        ]);

        $body = $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->getContent();

        $this->assertStringNotContainsString('postgres://super-secret', (string) $body);
    }

    public function test_variables_and_tags_can_be_synced_through_their_routes(): void
    {
        $variable = Variable::factory()->for($this->project)->create(['key' => 'API_URL']);

        $this->actingAs($this->owner)
            ->post(route('tags.store', $this->organization), ['name' => 'legacy', 'project_id' => $this->project->id])
            ->assertRedirect();

        $tag = Tag::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('variables.tags', [$this->organization, $this->project, $variable]), ['ids' => [$tag->id]])
            ->assertRedirect();

        $this->assertSame(['legacy'], $variable->fresh()->tags->pluck('name')->all());
    }

    public function test_a_tag_cannot_claim_both_a_project_and_an_environment_scope(): void
    {
        $this->actingAs($this->owner)
            ->post(route('tags.store', $this->organization), [
                'name' => 'confused',
                'project_id' => $this->project->id,
                'environment_id' => $this->prod->id,
            ])
            ->assertSessionHasErrors('scope');
    }

    public function test_only_a_tags_create_global_holder_may_create_an_organization_wide_tag(): void
    {
        // tags.create on a project makes project tags, never org-wide ones.
        $tagger = $this->joinMember($this->organization);
        $this->grant($tagger, Permission::CreateTags, $this->project);

        $this->actingAs($tagger)
            ->post(route('tags.store', $this->organization), ['name' => 'company-wide'])
            ->assertForbidden();
    }
}
