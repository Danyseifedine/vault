<?php

namespace Tests\Feature\Members;

use App\Actions\Members\SetMemberGrants;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Editing what a member may do - the one write path, its authorization, its
 * audit trail, and the lockout rule that keeps every organization governable.
 */
class MemberGrantsTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private Project $project;

    private Environment $dev;

    private User $manager;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->for($this->organization)->create();
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
        $this->manager = $this->organization->creator;
        $this->member = $this->joinMember($this->organization);
    }

    /** @param array<int, array<string, mixed>> $specs */
    private function setGrants(User $actor, array $specs, ?Project $scope = null): void
    {
        app(SetMemberGrants::class)($this->organization, $actor, $this->member, $specs, $scope);
    }

    public function test_a_manager_rewrites_a_members_set_and_it_is_audited(): void
    {
        $this->setGrants($this->manager, [
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
            ['permission' => Permission::InviteMembers->value],
        ]);

        $held = Grant::where('user_id', $this->member->id)->count();
        $this->assertSame(2, $held);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'member.grants-updated',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_an_empty_set_revokes_everything(): void
    {
        $this->grant($this->member, Permission::ViewVariables, $this->dev);

        $this->setGrants($this->manager, []);

        $this->assertSame(0, Grant::where('user_id', $this->member->id)->count());
    }

    public function test_someone_without_members_manage_is_refused_and_audited(): void
    {
        $bystander = $this->joinMember($this->organization);
        $this->grant($bystander, Permission::InviteMembers, $this->organization);

        try {
            $this->setGrants($bystander, []);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
        }

        $this->assertDatabaseHas('activity_log', [
            'event' => 'member.grants-denied',
            'causer_id' => $bystander->id,
        ]);
    }

    public function test_grants_can_only_be_written_for_an_actual_member(): void
    {
        $stranger = User::factory()->onboarded()->create();

        $this->expectException(ValidationException::class);

        app(SetMemberGrants::class)($this->organization, $this->manager, $stranger, []);
    }

    public function test_a_scoped_rewrite_cannot_touch_the_org_wide_rows(): void
    {
        $this->grant($this->member, Permission::ManagePins, $this->organization);
        $this->grant($this->member, Permission::ViewVariables, $this->dev);

        $this->setGrants($this->manager, [], $this->project);

        $left = Grant::where('user_id', $this->member->id)->get();

        $this->assertCount(1, $left);
        $this->assertSame(Permission::ManagePins, $left->first()->permission);
    }

    public function test_the_last_manager_cannot_lose_members_manage(): void
    {
        // The creator is the only joined holder of members.manage.
        $this->expectException(ValidationException::class);

        app(SetMemberGrants::class)(
            $this->organization,
            $this->manager,
            $this->manager,
            [['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id]],
        );
    }

    public function test_a_dormant_invitee_does_not_count_as_a_manager(): void
    {
        // Holds members.manage but never joined: their row must not unlock
        // stripping the only REAL manager.
        $ghost = User::factory()->create(['email' => 'ghost@acme.co']);
        $ghost->forceFill(['status' => UserStatus::Invited])->save();
        $this->organization->members()->attach($ghost->id, ['joined_at' => null]);
        $this->grant($ghost, Permission::ManageMembers, $this->organization);

        $this->expectException(ValidationException::class);

        app(SetMemberGrants::class)($this->organization, $this->manager, $this->manager, []);
    }

    public function test_anyone_is_revocable_once_a_second_joined_manager_exists(): void
    {
        $second = $this->joinMember($this->organization);
        $this->grant($second, Permission::ManageMembers, $this->organization);

        // Now even the creator - there is no hidden super-user.
        app(SetMemberGrants::class)($this->organization, $this->manager, $this->manager, []);

        $this->assertSame(0, Grant::where('user_id', $this->manager->id)->count());
    }

    public function test_the_route_rewrites_grants_end_to_end(): void
    {
        $this->actingAs($this->manager)
            ->put(route('members.grants', [$this->organization, $this->member]), [
                'grants' => [
                    ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
                ],
            ])
            ->assertRedirect();

        $row = Grant::where('user_id', $this->member->id)->sole();

        $this->assertSame(Permission::ViewVariables, $row->permission);
        $this->assertSame($this->dev->id, $row->environment_id);
    }

    public function test_the_route_scopes_to_a_project_when_asked(): void
    {
        $this->grant($this->member, Permission::ManagePins, $this->organization);

        $this->actingAs($this->manager)
            ->put(route('members.grants', [$this->organization, $this->member]), [
                'project_id' => $this->project->id,
                'grants' => [
                    ['permission' => Permission::UpdateVariables->value, 'environment_id' => $this->dev->id],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(2, Grant::where('user_id', $this->member->id)->count());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->put(route('members.grants', [$this->organization, $this->member]), ['grants' => []])
            ->assertRedirect(route('login'));
    }

    public function test_a_non_manager_gets_a_403_at_the_route(): void
    {
        $bystander = $this->joinMember($this->organization);

        $this->actingAs($bystander)
            ->put(route('members.grants', [$this->organization, $this->member]), ['grants' => []])
            ->assertForbidden();
    }
}
