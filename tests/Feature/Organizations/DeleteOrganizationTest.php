<?php

namespace Tests\Feature\Organizations;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Deleting an organization is the largest destructive act in the product: it
 * takes every project, environment, variable, value and version with it, for
 * everyone, and nothing brings them back.
 *
 * The audit log is the one thing that survives. It has to - the table is
 * append-only at the database level, and a record of a deletion that deletes
 * itself is not a record.
 */
class DeleteOrganizationTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    public function test_the_creator_can_delete_their_organization(): void
    {
        [$creator, $organization] = $this->created();

        $this->actingAs($creator)
            ->delete(route('organizations.destroy', $organization))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
    }

    /** The permission is the whole story - a plain member granted just organization.delete may pull the lever. */
    public function test_any_holder_of_organization_delete_can_delete_it(): void
    {
        [, $organization] = $this->created();
        $member = $this->joinMember($organization);
        $this->grant($member, Permission::DeleteOrganization, $organization);

        $this->actingAs($member)
            ->delete(route('organizations.destroy', $organization))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
    }

    public function test_everything_inside_goes_with_it(): void
    {
        [$creator, $organization] = $this->created();

        $project = Project::factory()->for($organization)->create();
        $environment = Environment::factory()->for($project)->create();
        $variable = Variable::factory()->for($project)->create();
        $value = VariableValue::create([
            'variable_id' => $variable->id,
            'environment_id' => $environment->id,
            'value' => 'sk_test_fake_value',
            'updated_by' => $creator->id,
        ]);

        $this->actingAs($creator)->delete(route('organizations.destroy', $organization));

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('environments', ['id' => $environment->id]);
        $this->assertDatabaseMissing('variables', ['id' => $variable->id]);
        $this->assertDatabaseMissing('variable_values', ['id' => $value->id]);
        $this->assertSame(0, DB::table('organization_user')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('grants')->where('organization_id', $organization->id)->count());
    }

    /** Not even a soft-deleted project may be left pointing at nothing. */
    public function test_a_soft_deleted_project_is_taken_too(): void
    {
        [$creator, $organization] = $this->created();
        $project = Project::factory()->for($organization)->create();
        $project->delete();

        $this->actingAs($creator)->delete(route('organizations.destroy', $organization));

        $this->assertSame(0, Project::withTrashed()->where('id', $project->id)->count());
    }

    public function test_the_audit_trail_survives_the_organization(): void
    {
        [$creator, $organization] = $this->created();
        $before = AuditLog::query()->count();

        $this->actingAs($creator)->delete(route('organizations.destroy', $organization));

        $this->assertGreaterThan($before, AuditLog::query()->count());
        $this->assertDatabaseHas('activity_log', [
            'event' => 'organization.deleted',
            'organization_id' => $organization->id,
        ]);
    }

    /** Near-power does not leak: every other administrative permission is not this one. */
    public function test_a_member_holding_other_administrative_permissions_cannot_delete_it(): void
    {
        [, $organization] = $this->created();
        $almost = $this->joinMember($organization);
        $this->grant($almost, [
            Permission::UpdateOrganization,
            Permission::UpdateSettings,
            Permission::ManageMembers,
        ], $organization);

        $this->actingAs($almost)
            ->delete(route('organizations.destroy', $organization))
            ->assertForbidden();

        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);
    }

    public function test_a_stranger_cannot_even_see_it(): void
    {
        [, $organization] = $this->created();

        $this->actingAs(User::factory()->onboarded()->create())
            ->delete(route('organizations.destroy', $organization))
            ->assertForbidden();

        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        [, $organization] = $this->created();

        $this->delete(route('organizations.destroy', $organization))->assertRedirect(route('login'));
    }

    public function test_a_refused_deletion_is_recorded(): void
    {
        [, $organization] = $this->created();
        $almost = $this->joinMember($organization);
        $this->grant($almost, Permission::UpdateSettings, $organization);

        $this->actingAs($almost)->delete(route('organizations.destroy', $organization));

        $this->assertDatabaseHas('activity_log', [
            'event' => 'organization.delete-denied',
            'organization_id' => $organization->id,
        ]);
    }

    /** @return array{0: User, 1: Organization} */
    private function created(): array
    {
        $creator = User::factory()->onboarded()->create();
        $organization = Organization::factory()->create(['created_by' => $creator->id]);

        return [$creator, $organization->refresh()];
    }
}
