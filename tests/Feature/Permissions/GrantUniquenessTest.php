<?php

namespace Tests\Feature\Permissions;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The grants table's integrity rules. The subtle one: a unique index over
 * nullable scope columns would NOT stop duplicates (both MySQL and SQLite let
 * every NULL through a unique index), so uniqueness runs over NULL-free
 * generated columns instead - and these tests prove it actually bites.
 */
class GrantUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->member = User::factory()->onboarded()->create();
        $this->organization->members()->attach($this->member->id, ['joined_at' => now()]);
    }

    private function grant(?int $projectId = null, ?int $environmentId = null): Grant
    {
        return Grant::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
            'project_id' => $projectId,
            'environment_id' => $environmentId,
            'permission' => Permission::ViewVariables->value,
            'created_by' => $this->organization->created_by,
        ]);
    }

    /** The member's rows only - the factory seeds the creator's twenty. */
    private function memberGrants(): Builder
    {
        return Grant::where('user_id', $this->member->id);
    }

    public function test_a_duplicate_org_scope_grant_is_refused_by_the_database(): void
    {
        $this->grant();

        // Both scope columns are NULL here - exactly the case a naive unique
        // index would wave through.
        $this->expectException(QueryException::class);

        $this->grant();
    }

    public function test_a_duplicate_environment_grant_is_refused_too(): void
    {
        $project = Project::factory()->for($this->organization)->create();
        $environment = Environment::factory()->for($project)->create();

        $this->grant($project->id, $environment->id);

        $this->expectException(QueryException::class);

        $this->grant($project->id, $environment->id);
    }

    public function test_the_same_permission_may_exist_at_different_scopes(): void
    {
        $project = Project::factory()->for($this->organization)->create();
        $environment = Environment::factory()->for($project)->create();

        $this->grant();
        $this->grant($project->id);
        $this->grant($project->id, $environment->id);

        $this->assertSame(3, $this->memberGrants()->count());
    }

    public function test_deleting_an_environment_takes_its_grant_rows_with_it(): void
    {
        $project = Project::factory()->for($this->organization)->create();
        $environment = Environment::factory()->for($project)->create();

        $this->grant($project->id, $environment->id);
        $this->grant($project->id);

        $environment->delete();

        $this->assertSame(1, $this->memberGrants()->count());
        $this->assertNull($this->memberGrants()->sole()->environment_id);
    }

    public function test_deleting_a_project_takes_its_grant_rows_with_it(): void
    {
        $project = Project::factory()->for($this->organization)->create();
        $environment = Environment::factory()->for($project)->create();

        $this->grant();
        $this->grant($project->id);
        $this->grant($project->id, $environment->id);

        $project->forceDelete();

        $this->assertSame(1, $this->memberGrants()->count());
        $this->assertNull($this->memberGrants()->sole()->project_id);
    }

    public function test_deleting_the_member_takes_their_grant_rows_with_them(): void
    {
        $this->grant();

        $this->member->delete();

        $this->assertSame(0, Grant::where('user_id', $this->member->id)->count());
    }

    public function test_deleting_the_granter_keeps_the_grant_but_forgets_the_hand(): void
    {
        $granter = User::factory()->onboarded()->create();

        Grant::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
            'permission' => Permission::RevealValues->value,
            'created_by' => $granter->id,
        ]);

        $granter->delete();

        $grant = $this->memberGrants()
            ->where('permission', Permission::RevealValues->value)
            ->sole();

        $this->assertNull($grant->created_by);
    }
}
