<?php

namespace Tests\Feature\Members;

use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\GrantWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The one place that turns a wire payload of grant specs into rows: it must
 * refuse everything malformed or foreign, and its replace must be a real
 * replace - additive drift is how ghosts of old access survive.
 */
class GrantWriterTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private Project $project;

    private Environment $dev;

    private User $member;

    private User $actor;

    private GrantWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->for($this->organization)->create();
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
        $this->member = $this->joinMember($this->organization);
        $this->actor = $this->organization->creator;
        $this->writer = app(GrantWriter::class);
    }

    /** @param array<int, array<string, mixed>> $specs */
    private function replace(array $specs, ?Project $scope = null): array
    {
        return $this->writer->replace($this->organization, $this->member, $specs, $this->actor, $scope);
    }

    private function memberRows(): Builder
    {
        return Grant::where('user_id', $this->member->id);
    }

    public function test_an_unknown_permission_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->replace([['permission' => 'variables.summon']]);
    }

    public function test_an_environment_from_another_organization_is_refused(): void
    {
        $foreign = Environment::factory()
            ->for(Project::factory()->for(Organization::factory()->create()))
            ->create();

        $this->expectException(ValidationException::class);

        $this->replace([
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $foreign->id],
        ]);
    }

    public function test_a_project_from_another_organization_is_refused(): void
    {
        $foreign = Project::factory()->for(Organization::factory()->create())->create();

        $this->expectException(ValidationException::class);

        $this->replace([
            ['permission' => Permission::UpdateSettings->value, 'project_id' => $foreign->id],
        ]);
    }

    public function test_a_mismatched_project_and_environment_pair_is_refused(): void
    {
        $sibling = Project::factory()->for($this->organization)->create();

        $this->expectException(ValidationException::class);

        $this->replace([[
            'permission' => Permission::ViewVariables->value,
            'project_id' => $sibling->id,
            'environment_id' => $this->dev->id,
        ]]);
    }

    public function test_an_org_native_permission_cannot_be_scoped_to_a_project(): void
    {
        // "Invite members, but only in the billing project" is nonsense.
        $this->expectException(ValidationException::class);

        $this->replace([
            ['permission' => Permission::InviteMembers->value, 'project_id' => $this->project->id],
        ]);
    }

    public function test_a_project_native_permission_cannot_be_scoped_to_an_environment(): void
    {
        $this->expectException(ValidationException::class);

        $this->replace([
            ['permission' => Permission::UpdateSettings->value, 'environment_id' => $this->dev->id],
        ]);
    }

    public function test_an_environment_spec_gets_its_project_filled_in(): void
    {
        $this->replace([
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
        ]);

        $row = $this->memberRows()->sole();

        $this->assertSame($this->project->id, $row->project_id);
        $this->assertSame($this->dev->id, $row->environment_id);
    }

    public function test_replace_adds_removes_and_keeps_in_one_move(): void
    {
        $this->replace([
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
            ['permission' => Permission::RevealValues->value, 'environment_id' => $this->dev->id],
        ]);

        [$granted, $revoked] = $this->replace([
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
            ['permission' => Permission::ManagePins->value],
        ]);

        $this->assertSame(1, $granted);
        $this->assertSame(1, $revoked);

        $held = $this->memberRows()
            ->pluck('permission')
            ->map(fn (Permission $permission) => $permission->value)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['pins.manage', 'variables.view'], $held);
    }

    public function test_replacing_with_the_same_set_changes_nothing(): void
    {
        $specs = [
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
        ];

        $this->replace($specs);

        [$granted, $revoked] = $this->replace($specs);

        $this->assertSame(0, $granted);
        $this->assertSame(0, $revoked);
        $this->assertSame(1, $this->memberRows()->count());
    }

    public function test_an_empty_set_revokes_everything(): void
    {
        $this->replace([
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
            ['permission' => Permission::ManagePins->value],
        ]);

        $this->replace([]);

        $this->assertSame(0, $this->memberRows()->count());
    }

    public function test_a_project_scoped_replace_leaves_the_rest_of_the_world_alone(): void
    {
        $sibling = Project::factory()->for($this->organization)->create();
        $siblingEnv = Environment::factory()->for($sibling)->create(['name' => 'prod']);

        $this->replace([
            ['permission' => Permission::ManagePins->value],
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $siblingEnv->id],
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
        ]);

        // Rewrite ONLY this project's rows: dev's view goes, update comes.
        $this->writer->replace($this->organization, $this->member, [
            ['permission' => Permission::UpdateVariables->value, 'environment_id' => $this->dev->id],
        ], $this->actor, $this->project);

        $held = $this->memberRows()
            ->get()
            ->map(fn (Grant $grant) => [$grant->permission->value, $grant->project_id])
            ->all();

        $this->assertContains(['pins.manage', null], $held);
        $this->assertContains(['variables.view', $sibling->id], $held);
        $this->assertContains(['variables.update', $this->project->id], $held);
        $this->assertCount(3, $held);
    }

    public function test_a_scoped_replace_refuses_rows_outside_its_project(): void
    {
        $this->expectException(ValidationException::class);

        $this->writer->replace($this->organization, $this->member, [
            ['permission' => Permission::ManagePins->value],
        ], $this->actor, $this->project);
    }

    public function test_seed_full_access_writes_one_org_row_per_permission(): void
    {
        $user = $this->joinMember($this->organization);

        $this->writer->seedFullAccess($this->organization, $user);

        $rows = Grant::where('user_id', $user->id)->get();

        $this->assertCount(count(Permission::cases()), $rows);
        $this->assertTrue($rows->every(
            fn (Grant $grant) => $grant->project_id === null && $grant->environment_id === null,
        ));
    }

    public function test_duplicate_specs_in_one_payload_collapse_to_one_row(): void
    {
        $this->replace([
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
        ]);

        $this->assertSame(1, $this->memberRows()->count());
    }
}
