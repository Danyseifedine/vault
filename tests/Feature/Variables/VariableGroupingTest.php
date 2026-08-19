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
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Filing a variable under a group, from the only place a group is ever chosen:
 * the variable dialog. A name instead of an id means a new group, and that goes
 * through CreateGroup like every other group - same permission, same audit
 * entry - so the dialog cannot become a quiet way around `groups.manage`.
 */
class VariableGroupingTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function store(User $actor, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor)->post(
            route('variables.store', [$this->organization, $this->project]),
            [
                'key' => 'DATABASE_URL',
                'sensitivity' => 'sensitive',
                'change_safety' => 'safe',
                ...$overrides,
            ],
        );
    }

    private function events(): array
    {
        return DB::table('activity_log')->pluck('event')->all();
    }

    public function test_a_variable_can_be_filed_under_an_existing_group(): void
    {
        $group = Group::create(['project_id' => $this->project->id, 'name' => 'Database']);

        $this->store($this->owner, ['group_id' => $group->id])->assertRedirect();

        $this->assertSame($group->id, Variable::query()->firstOrFail()->group_id);
    }

    public function test_naming_a_group_that_does_not_exist_creates_it_and_files_the_variable(): void
    {
        $this->store($this->owner, ['group_name' => 'Database'])->assertRedirect();

        $group = Group::query()->where('name', 'Database')->firstOrFail();

        $this->assertSame($this->project->id, $group->project_id);
        $this->assertSame($group->id, Variable::query()->firstOrFail()->group_id);
        // Created through CreateGroup, so it left the same trail as any group.
        $this->assertContains('group.created', $this->events());
    }

    /** Typing a name that already exists reuses it rather than failing a save. */
    public function test_an_existing_group_name_is_reused_whatever_the_casing(): void
    {
        $group = Group::create(['project_id' => $this->project->id, 'name' => 'Database']);

        $this->store($this->owner, ['group_name' => '  dATABASE '])->assertRedirect();

        $this->assertSame(1, Group::query()->count());
        $this->assertSame($group->id, Variable::query()->firstOrFail()->group_id);
    }

    public function test_creating_a_group_from_the_dialog_still_takes_groups_manage(): void
    {
        $writer = $this->joinMember($this->organization);
        $this->grantWriter($writer, $this->project->environments()->firstOrFail());

        $this->store($writer, ['group_name' => 'Database'])->assertForbidden();

        $this->assertSame(0, Group::query()->count());
        // The variable is not created either - the refusal comes first.
        $this->assertSame(0, Variable::query()->count());
        $this->assertContains('group.create-denied', $this->events());
    }

    public function test_a_writer_without_groups_manage_can_still_use_an_existing_group(): void
    {
        $group = Group::create(['project_id' => $this->project->id, 'name' => 'Database']);

        $writer = $this->joinMember($this->organization);
        $this->grantWriter($writer, $this->project->environments()->firstOrFail());

        $this->store($writer, ['group_id' => $group->id])->assertRedirect();

        $this->assertSame($group->id, Variable::query()->firstOrFail()->group_id);
    }

    public function test_a_variable_can_be_moved_between_groups_and_back_to_ungrouped(): void
    {
        $database = Group::create(['project_id' => $this->project->id, 'name' => 'Database']);
        $cache = Group::create(['project_id' => $this->project->id, 'name' => 'Cache']);

        $this->store($this->owner, ['group_id' => $database->id]);
        $variable = Variable::query()->firstOrFail();

        $update = fn (array $payload) => $this->actingAs($this->owner)->patch(
            route('variables.update', [$this->organization, $this->project, $variable]),
            [
                'key' => 'DATABASE_URL',
                'sensitivity' => 'sensitive',
                'change_safety' => 'safe',
                ...$payload,
            ],
        );

        $update(['group_id' => $cache->id])->assertRedirect();
        $this->assertSame($cache->id, $variable->fresh()->group_id);

        $update(['group_id' => null])->assertRedirect();
        $this->assertNull($variable->fresh()->group_id);
    }

    public function test_a_group_belonging_to_another_project_is_refused(): void
    {
        $other = app(CreateProject::class)($this->organization, $this->owner, 'Billing');
        $foreign = Group::create(['project_id' => $other->id, 'name' => 'Theirs']);

        $this->store($this->owner, ['group_id' => $foreign->id])
            ->assertSessionHasErrors('group_id');

        $this->assertSame(0, Variable::query()->count());
    }

    public function test_a_group_name_that_is_too_long_is_refused(): void
    {
        $this->store($this->owner, ['group_name' => str_repeat('a', 121)])
            ->assertSessionHasErrors('group_name');
    }

    public function test_the_project_payload_offers_the_groups_the_picker_lists(): void
    {
        $this->store($this->owner, ['group_name' => 'Database']);

        $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertInertia(fn ($page) => $page
                ->where('admin.groups.0.name', 'Database')
                ->where('admin.groups.0.variables', 1)
                ->where('admin.can.manageGroups', true)
                ->where('variables.0.group', 'Database')
                ->etc());
    }

    public function test_a_member_who_cannot_manage_groups_is_told_so(): void
    {
        $writer = $this->joinMember($this->organization);
        $this->grant($writer, Permission::ViewVariables, $this->project->environments()->firstOrFail());

        $this->actingAs($writer)
            ->get(route('projects.show', [$this->organization, $this->project]))
            ->assertInertia(fn ($page) => $page
                ->where('admin.can.manageGroups', false)
                ->etc());
    }

    public function test_a_guest_cannot_create_a_group_through_the_variable_route(): void
    {
        $this->post(
            route('variables.store', [$this->organization, $this->project]),
            ['key' => 'DATABASE_URL', 'group_name' => 'Database'],
        )->assertRedirect(route('login'));

        $this->assertSame(0, Group::query()->count());
    }
}
