<?php

namespace Tests\Feature\Organizations;

use App\Actions\Invitations\ResendInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Actions\Invitations\SendInvitation;
use App\Enums\Permission;
use App\Mail\InvitationMail;
use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->organization->creator;
        $this->project = Project::factory()->for($this->organization)->create();
    }

    private function environment(string $name): Environment
    {
        return Environment::factory()->for($this->project)->create(['name' => $name]);
    }

    /** @param array<int, array<string, mixed>> $grants */
    private function invite(string $email = 'hadi@acme.test', array $grants = []): Invitation
    {
        return app(SendInvitation::class)($this->organization, $this->owner, $email, $grants);
    }

    public function test_inviting_reserves_an_inert_account_with_its_grants_already_applied(): void
    {
        $dev = $this->environment('dev');
        $prod = $this->environment('prod');

        $invitation = $this->invite(grants: [
            ['permission' => Permission::UpdateVariables->value, 'environment_id' => $dev->id],
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $prod->id],
        ]);

        $user = $invitation->user;

        $this->assertTrue($user->isInvited());
        $this->assertNull($user->password);
        $this->assertNull($user->name);

        // The grants exist NOW - the members list can show them immediately.
        $this->assertDatabaseHas('grants', [
            'user_id' => $user->id,
            'environment_id' => $dev->id,
            'permission' => Permission::UpdateVariables->value,
        ]);
        $this->assertDatabaseHas('grants', [
            'user_id' => $user->id,
            'environment_id' => $prod->id,
            'permission' => Permission::ViewVariables->value,
        ]);

        // A seat, not a membership: the pivot exists but joined_at stays empty
        // until the person claims it.
        $this->assertTrue($this->organization->hasMember($user));
        $this->assertNull($this->organization->members()->find($user->id)->pivot->joined_at);

        Mail::assertQueued(InvitationMail::class);

        $entry = AuditLog::query()->where('event', 'invitation.sent')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame(2, $entry->properties['grants']);
    }

    public function test_an_invited_account_cannot_be_used_for_anything_yet(): void
    {
        $invitation = $this->invite();

        $this->actingAs($invitation->user)->get('/dashboard')->assertRedirect(route('onboarding.show'));
    }

    public function test_the_landing_page_shows_what_is_being_offered(): void
    {
        $invitation = $this->invite();

        $this->get(route('invitations.show', $invitation->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/invitation')
                ->where('state', 'pending')
                ->where('invitedEmail', 'hadi@acme.test'));
    }

    public function test_accepting_signs_the_invitee_in_and_activates_nothing_extra(): void
    {
        $dev = $this->environment('dev');
        $invitation = $this->invite(grants: [
            ['permission' => Permission::UpdateVariables->value, 'environment_id' => $dev->id],
        ]);

        $this->post(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('onboarding.show'));

        $this->assertAuthenticatedAs($invitation->user->fresh());

        $invitation->refresh();
        $this->assertTrue($invitation->isAccepted());
        $this->assertNotNull($invitation->user->email_verified_at);
        $this->assertNotNull($this->organization->members()->find($invitation->user_id)->pivot->joined_at);

        // Grants are untouched by acceptance - they were right all along.
        $this->assertSame(1, Grant::query()->where('user_id', $invitation->user_id)->count());
        $this->assertDatabaseHas('grants', [
            'user_id' => $invitation->user_id,
            'environment_id' => $dev->id,
            'permission' => Permission::UpdateVariables->value,
        ]);
        $this->assertDatabaseHas('activity_log', ['event' => 'invitation.accepted']);
    }

    public function test_an_expired_invitation_is_refused_with_a_reason(): void
    {
        $invitation = $this->invite();
        $invitation->forceFill(['expires_at' => now()->subDay()])->save();

        $this->get(route('invitations.show', $invitation->token))
            ->assertInertia(fn ($page) => $page->where('state', 'expired'));

        $this->post(route('invitations.accept', $invitation->token))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_revoked_invitation_is_refused_and_its_seat_is_gone(): void
    {
        $dev = $this->environment('dev');
        $invitation = $this->invite(grants: [
            ['permission' => Permission::ViewVariables->value, 'environment_id' => $dev->id],
        ]);
        $userId = $invitation->user_id;

        $this->assertSame(1, Grant::query()->where('user_id', $userId)->count());

        app(RevokeInvitation::class)($invitation, $this->owner);

        // The reserved account goes, and its dormant grant rows cascade with it.
        $this->assertDatabaseMissing('users', ['id' => $userId]);
        $this->assertDatabaseMissing('grants', ['user_id' => $userId]);
        $this->assertDatabaseHas('activity_log', ['event' => 'invitation.revoked']);
    }

    public function test_an_already_accepted_token_cannot_be_replayed(): void
    {
        $invitation = $this->invite();
        $this->post(route('invitations.accept', $invitation->token));
        $this->post('/logout');

        $this->post(route('invitations.accept', $invitation->token))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_unknown_token_reveals_nothing(): void
    {
        $this->get(route('invitations.show', 'not-a-real-token'))
            ->assertInertia(fn ($page) => $page->where('state', 'unknown')->missing('invitedEmail'));
    }

    public function test_signing_in_as_someone_else_is_called_out_not_silently_accepted(): void
    {
        $invitation = $this->invite();
        $someoneElse = User::factory()->onboarded()->create();

        $this->actingAs($someoneElse)
            ->get(route('invitations.show', $invitation->token))
            ->assertInertia(fn ($page) => $page
                ->where('state', 'wrong-account')
                ->where('signedInEmail', $someoneElse->email));

        $this->actingAs($someoneElse)->post(route('invitations.accept', $invitation->token));

        $this->assertFalse($invitation->fresh()->isAccepted());
    }

    public function test_a_second_pending_invitation_for_the_same_person_is_blocked(): void
    {
        $this->invite('hadi@acme.test');

        $this->expectException(ValidationException::class);

        $this->invite('hadi@acme.test');
    }

    public function test_resending_replaces_the_old_link(): void
    {
        $invitation = $this->invite();
        $originalToken = $invitation->token;

        app(ResendInvitation::class)($invitation, $this->owner);

        $this->assertNotSame($originalToken, $invitation->fresh()->token);
        $this->get(route('invitations.show', $originalToken))
            ->assertInertia(fn ($page) => $page->where('state', 'unknown'));
    }

    public function test_the_invited_email_is_normalised(): void
    {
        $invitation = $this->invite('  HADI@Acme.Test  ');

        $this->assertSame('hadi@acme.test', $invitation->user->email);
    }
}
