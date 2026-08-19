<?php

namespace Tests\Feature\Organizations;

use App\Actions\Invitations\SendInvitation;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The invite door must not out-rank the people door: members.invite alone
 * mints accounts with environment actions only, and anything administrative
 * riding on an invite takes members.manage - otherwise an inviter could
 * fabricate a colleague more powerful than anyone they could edit.
 */
class InvitePrivilegeTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private Project $project;

    private Environment $dev;

    private User $inviter;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->for($this->organization)->create();
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);

        // members.invite and nothing else - the narrow recruiter.
        $this->inviter = $this->joinMember($this->organization);
        $this->grant($this->inviter, Permission::InviteMembers, $this->organization);
    }

    public function test_an_inviter_hands_out_environment_actions_freely(): void
    {
        app(SendInvitation::class)(
            $this->organization,
            $this->inviter,
            'dev@acme.co',
            [['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id]],
        );

        $invited = User::query()->where('email', 'dev@acme.co')->sole();

        $this->assertSame(1, Grant::where('user_id', $invited->id)->count());
    }

    public function test_attaching_an_administrative_permission_takes_members_manage(): void
    {
        try {
            app(SendInvitation::class)(
                $this->organization,
                $this->inviter,
                'boss@acme.co',
                [['permission' => Permission::ManageMembers->value]],
            );
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
        }

        // Refused BEFORE anything was created, and on the record.
        $this->assertDatabaseMissing('users', ['email' => 'boss@acme.co']);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'invitation.privilege-denied',
            'causer_id' => $this->inviter->id,
        ]);
    }

    public function test_project_shaped_permissions_are_administrative_too(): void
    {
        $this->expectException(AuthorizationException::class);

        app(SendInvitation::class)(
            $this->organization,
            $this->inviter,
            'shaper@acme.co',
            [['permission' => Permission::UpdateSettings->value, 'project_id' => $this->project->id]],
        );
    }

    public function test_a_members_manage_holder_attaches_anything(): void
    {
        app(SendInvitation::class)(
            $this->organization,
            $this->organization->creator,
            'admin@acme.co',
            [
                ['permission' => Permission::ManageMembers->value],
                ['permission' => Permission::UpdateSettings->value, 'project_id' => $this->project->id],
                ['permission' => Permission::RevealValues->value, 'environment_id' => $this->dev->id],
            ],
        );

        $invited = User::query()->where('email', 'admin@acme.co')->sole();

        $this->assertSame(3, Grant::where('user_id', $invited->id)->count());
    }

    public function test_the_route_refuses_the_same_escalation(): void
    {
        $this->actingAs($this->inviter)
            ->post(route('members.invite', $this->organization), [
                'email' => 'boss@acme.co',
                'grants' => [['permission' => Permission::ManageMembers->value]],
            ])
            ->assertForbidden();
    }

    public function test_a_re_invite_replaces_the_dormant_grants(): void
    {
        $send = app(SendInvitation::class);

        $send($this->organization, $this->organization->creator, 'dev@acme.co', [
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
            ['permission' => Permission::RevealValues->value, 'environment_id' => $this->dev->id],
        ]);

        $invited = User::query()->where('email', 'dev@acme.co')->sole();
        $this->assertSame(2, Grant::where('user_id', $invited->id)->count());

        // Clear the pending invite, then invite again with a smaller set: the
        // new invitation states the whole truth, nothing accumulates.
        Invitation::query()->update(['revoked_at' => now()]);

        $send($this->organization, $this->organization->creator, 'dev@acme.co', [
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->dev->id],
        ]);

        $this->assertSame(1, Grant::where('user_id', $invited->id)->count());
    }
}
