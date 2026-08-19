<?php

namespace Tests\Feature\Variables;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Projects\CreateProject;
use App\Enums\Permission;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Naming the ungrouped bucket: the loose variables get a home. "Ungrouped" is
 * not a row, so this is the mirror of deleting a group - it creates or reuses a
 * group and moves every group-less variable into it, under groups.manage.
 */
class AdoptUngroupedTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');
        $this->project = app(CreateProject::class)($this->organization, $this->owner, 'API');
    }

    private function ungroupedVariable(string $key): Variable
    {
        return Variable::factory()->for($this->project)->create([
            'key' => $key,
            'group_id' => null,
        ]);
    }

    private function adopt(User $actor, string $name = 'Misc')
    {
        return $this->actingAs($actor)->post(
            route('groups.adopt-ungrouped', [$this->organization, $this->project]),
            ['name' => $name],
        );
    }

    public function test_naming_ungrouped_creates_a_group_and_moves_the_loose_variables(): void
    {
        $a = $this->ungroupedVariable('A_KEY');
        $b = $this->ungroupedVariable('B_KEY');

        $this->adopt($this->owner, 'Misc')->assertRedirect();

        $group = Group::where('project_id', $this->project->id)->where('name', 'Misc')->first();
        $this->assertNotNull($group);
        $this->assertSame($group->id, $a->fresh()->group_id);
        $this->assertSame($group->id, $b->fresh()->group_id);
        $this->assertDatabaseHas('activity_log', ['event' => 'group.adopted-ungrouped']);
    }

    public function test_an_existing_group_name_is_reused_not_duplicated(): void
    {
        $existing = $this->project->groups()->create(['name' => 'Shared', 'position' => 0]);
        $loose = $this->ungroupedVariable('LOOSE');

        $this->adopt($this->owner, 'shared')->assertRedirect();

        $this->assertSame(1, Group::where('project_id', $this->project->id)->count());
        $this->assertSame($existing->id, $loose->fresh()->group_id);
    }

    public function test_only_ungrouped_variables_move(): void
    {
        $group = $this->project->groups()->create(['name' => 'Existing', 'position' => 0]);
        $grouped = Variable::factory()->for($this->project)->create([
            'key' => 'ALREADY',
            'group_id' => $group->id,
        ]);
        $loose = $this->ungroupedVariable('LOOSE');

        $this->adopt($this->owner, 'Misc')->assertRedirect();

        // The already-grouped variable is untouched; only the loose one moved.
        $this->assertSame($group->id, $grouped->fresh()->group_id);
        $this->assertNotSame($group->id, $loose->fresh()->group_id);
    }

    public function test_it_takes_groups_manage(): void
    {
        $member = $this->joinMember($this->organization);
        // Can see and even update variables, but may not manage groups.
        $this->grant($member, [Permission::ViewVariables, Permission::UpdateVariables], $this->project->environments()->first());
        $loose = $this->ungroupedVariable('LOOSE');

        $this->adopt($member, 'Misc')->assertForbidden();

        $this->assertNull($loose->fresh()->group_id);
        $this->assertDatabaseHas('activity_log', ['event' => 'group.adopt-denied']);
    }

    public function test_the_name_is_validated(): void
    {
        $this->ungroupedVariable('LOOSE');

        $this->actingAs($this->owner)
            ->post(route('groups.adopt-ungrouped', [$this->organization, $this->project]), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->owner)
            ->post(route('groups.adopt-ungrouped', [$this->organization, $this->project]), ['name' => str_repeat('x', 61)])
            ->assertSessionHasErrors('name');
    }
}
