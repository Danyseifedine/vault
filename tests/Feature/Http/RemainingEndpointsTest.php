<?php

namespace Tests\Feature\Http;

use App\Actions\Invitations\SendInvitation;
use App\Actions\PersonalVault\CreatePersonalSecret;
use App\Actions\Tags\CreateTag;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Permission;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\PersonalSecret;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The endpoints that closed the last gaps: grant tuning, environment renames,
 * invitation maintenance, tag upkeep, personal edits, and the diff.
 */
class RemainingEndpointsTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private Organization $organization;

    private Project $project;

    private Environment $prod;

    private Environment $dev;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->organization->creator;
        $this->project = Project::factory()->for($this->organization)->create(['name' => 'API']);
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
        $this->dev = Environment::factory()->for($this->project)->create(['name' => 'dev']);
    }

    private function reader(?Environment $environment = null): User
    {
        $user = $this->joinMember($this->organization);
        $this->grantReader($user, $environment ?? $this->prod);

        return $user;
    }

    // ── Grant tuning ────────────────────────────────────────────────────────

    public function test_a_manager_tunes_a_single_action_through_the_route(): void
    {
        $reader = $this->reader();

        $this->actingAs($this->owner)
            ->put(route('members.grants', [$this->organization, $reader]), [
                'grants' => [
                    ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->prod->id],
                    ['permission' => Permission::RevealValues->value, 'environment_id' => $this->prod->id],
                    ['permission' => Permission::ExportEnv->value, 'environment_id' => $this->prod->id],
                    ['permission' => Permission::UpdateVariables->value, 'environment_id' => $this->prod->id],
                ],
            ])
            ->assertRedirect();

        $this->assertTrue(
            app(AccessResolver::class)->can($reader, Permission::UpdateVariables, $this->prod->fresh()),
        );
    }

    public function test_a_tuned_permission_actually_changes_what_the_route_allows(): void
    {
        $reader = $this->reader();
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');

        $url = route('values.update', [$this->organization, $this->project, $variable, $this->prod]);

        $this->actingAs($reader)->put($url, ['value' => 'nope'])->assertForbidden();

        $this->actingAs($this->owner)
            ->put(route('members.grants', [$this->organization, $reader]), [
                'grants' => [
                    ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->prod->id],
                    ['permission' => Permission::UpdateVariables->value, 'environment_id' => $this->prod->id],
                ],
            ]);

        $this->actingAs($reader)->put($url, ['value' => 'postgres://fake'])->assertRedirect();

        $this->assertSame('postgres://fake', $variable->fresh()->valueIn($this->prod)?->value);
    }

    public function test_an_administrative_permission_cannot_ride_on_an_environment_row(): void
    {
        $reader = $this->reader();

        $this->actingAs($this->owner)
            ->put(route('members.grants', [$this->organization, $reader]), [
                'grants' => [
                    ['permission' => Permission::UpdateSettings->value, 'environment_id' => $this->prod->id],
                ],
            ])
            ->assertSessionHasErrors('grants.0');
    }

    public function test_a_plain_member_cannot_tune_permissions(): void
    {
        $actor = $this->joinMember($this->organization);
        $this->grantFullAccess($actor, $this->prod);
        $target = $this->reader();

        $this->actingAs($actor)
            ->put(route('members.grants', [$this->organization, $target]), ['grants' => []])
            ->assertForbidden();
    }

    // ── Value history & rollback ────────────────────────────────────────────

    public function test_the_history_lists_versions_without_ever_returning_a_value(): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');
        app(SetVariableValue::class)($variable, $this->prod, $this->owner, 'postgres://first-secret');
        app(SetVariableValue::class)($variable, $this->prod, $this->owner, 'postgres://second-secret');

        $response = $this->actingAs($this->owner)
            ->getJson(route('values.history', [$this->organization, $this->project, $variable, $this->prod]));

        $response->assertOk()->assertJsonStructure(['versions' => [['id', 'version', 'by', 'at', 'current']]]);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('first-secret', $body);
        $this->assertStringNotContainsString('second-secret', $body);
    }

    public function test_a_reader_cannot_see_the_history(): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');
        app(SetVariableValue::class)($variable, $this->prod, $this->owner, 'x');

        $this->actingAs($this->reader())
            ->getJson(route('values.history', [$this->organization, $this->project, $variable, $this->prod]))
            ->assertForbidden();
    }

    public function test_rolling_back_restores_an_earlier_value_as_a_new_version(): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');
        app(SetVariableValue::class)($variable, $this->prod, $this->owner, 'postgres://original');
        app(SetVariableValue::class)($variable, $this->prod, $this->owner, 'postgres://broken');

        // `versions()` already orders newest-first, so ask for v1 by name
        // rather than adding a second, ignored order clause.
        $original = $variable->valueIn($this->prod)->versions()->where('version', 1)->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('values.rollback', [$this->organization, $this->project, $variable, $this->prod, $original]))
            ->assertRedirect();

        $current = $variable->fresh()->valueIn($this->prod);

        $this->assertSame('postgres://original', $current->value);
        // History is written forward, never rewritten.
        $this->assertSame(3, $current->version);
    }

    // ── Environment rename ──────────────────────────────────────────────────

    public function test_an_environment_is_renamed_through_its_route(): void
    {
        $this->actingAs($this->owner)
            ->patch(route('environments.update', [$this->organization, $this->project, $this->dev]), [
                'name' => 'Development',
            ])
            ->assertRedirect();

        $this->assertSame('development', $this->dev->fresh()->slug);
    }

    public function test_a_member_cannot_rename_an_environment(): void
    {
        $member = $this->joinMember($this->organization);
        $this->grantFullAccess($member, $this->dev);

        $this->actingAs($member)
            ->patch(route('environments.update', [$this->organization, $this->project, $this->dev]), [
                'name' => 'Development',
            ])
            ->assertForbidden();
    }

    // ── Invitations ─────────────────────────────────────────────────────────

    public function test_an_invitation_can_be_resent_and_the_old_link_stops_working(): void
    {
        $invitation = app(SendInvitation::class)(
            $this->organization, $this->owner, 'newcomer@lebify.test',
        );
        $originalToken = $invitation->token;

        $this->actingAs($this->owner)
            ->post(route('invitations.resend', [$this->organization, $invitation]))
            ->assertRedirect();

        $this->assertNotSame($originalToken, $invitation->fresh()->token);

        // The old link is dead, and says so plainly rather than 404ing.
        $this->get(route('invitations.show', $originalToken))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/invitation')->where('state', 'unknown'));

        $this->post(route('invitations.accept', $originalToken))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    public function test_revoking_an_invitation_deletes_the_reserved_account(): void
    {
        $invitation = app(SendInvitation::class)(
            $this->organization, $this->owner, 'newcomer@lebify.test',
            [['permission' => Permission::ViewVariables->value, 'environment_id' => $this->prod->id]],
        );

        $this->actingAs($this->owner)
            ->delete(route('invitations.destroy', [$this->organization, $invitation]))
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'newcomer@lebify.test']);

        // The dormant grant went down with the account.
        $this->assertSame(0, DB::table('grants')->where('environment_id', $this->prod->id)->count());
    }

    public function test_a_plain_member_cannot_revoke_an_invitation(): void
    {
        $invitation = app(SendInvitation::class)(
            $this->organization, $this->owner, 'newcomer@lebify.test',
        );

        $this->actingAs($this->reader())
            ->delete(route('invitations.destroy', [$this->organization, $invitation]))
            ->assertForbidden();
    }

    // ── Tags ────────────────────────────────────────────────────────────────

    public function test_a_tag_is_renamed_and_deleted_through_its_routes(): void
    {
        $tag = app(CreateTag::class)->forProject($this->project, $this->owner, 'legacy');

        $this->actingAs($this->owner)
            ->patch(route('tags.update', [$this->organization, $tag]), ['name' => 'deprecated'])
            ->assertRedirect();

        $this->assertSame('deprecated', (string) $tag->fresh()->name);

        $this->actingAs($this->owner)
            ->delete(route('tags.destroy', [$this->organization, $tag]))
            ->assertRedirect();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_a_tag_from_another_organization_is_unreachable(): void
    {
        $other = Organization::factory()->create();
        $foreign = app(CreateTag::class)->global($other, $other->creator, 'theirs');

        $this->actingAs($this->owner)
            ->delete(route('tags.destroy', [$this->organization, $foreign]))
            ->assertNotFound();
    }

    // ── Personal edits ──────────────────────────────────────────────────────

    public function test_the_owner_edits_their_own_item_without_touching_its_value(): void
    {
        $secret = app(CreatePersonalSecret::class)($this->owner, 'Token', 'ghp_fake_123');

        $this->actingAs($this->owner)
            ->patch(route('personal.update', $secret), ['name' => 'GitHub token'])
            ->assertRedirect();

        $this->assertSame('GitHub token', $secret->fresh()->name);
        $this->assertSame('ghp_fake_123', $secret->fresh()->value);
    }

    public function test_nobody_else_can_edit_a_personal_item(): void
    {
        $secret = app(CreatePersonalSecret::class)($this->owner, 'Token', 'fake');

        $this->actingAs($this->reader())
            ->patch(route('personal.update', $secret), ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Token', PersonalSecret::query()->findOrFail($secret->id)->name);
    }

    // ── Diff ────────────────────────────────────────────────────────────────

    public function test_the_diff_reports_status_without_ever_returning_a_value(): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'DATABASE_URL');
        app(SetVariableValue::class)($variable, $this->prod, $this->owner, 'postgres://prod-secret');
        app(SetVariableValue::class)($variable, $this->dev, $this->owner, 'postgres://dev-secret');

        $response = $this->actingAs($this->owner)
            ->getJson(route('projects.diff', [$this->organization, $this->project]).'?left=dev&right=prod');

        $response->assertOk()->assertJsonPath('rows.0.status', 'differs');

        $this->assertStringNotContainsString('prod-secret', $response->getContent() ?: '');
        $this->assertStringNotContainsString('dev-secret', $response->getContent() ?: '');
    }

    public function test_a_missing_key_is_reported_as_missing(): void
    {
        $variable = app(CreateVariable::class)($this->project, $this->owner, 'ONLY_IN_DEV');
        app(SetVariableValue::class)($variable, $this->dev, $this->owner, 'x');

        $this->actingAs($this->owner)
            ->getJson(route('projects.diff', [$this->organization, $this->project]).'?left=dev&right=prod')
            ->assertOk()
            ->assertJsonPath('rows.0.status', 'missing');
    }

    /** "differs" is information about prod, so it takes access to prod. */
    public function test_the_diff_needs_view_access_to_both_sides(): void
    {
        $devOnly = $this->reader($this->dev);

        $this->actingAs($devOnly)
            ->getJson(route('projects.diff', [$this->organization, $this->project]).'?left=dev&right=prod')
            ->assertForbidden();
    }

    public function test_the_diff_needs_two_environments(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('projects.diff', [$this->organization, $this->project]).'?left=dev')
            ->assertStatus(422);
    }

    public function test_the_diff_is_audited(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('projects.diff', [$this->organization, $this->project]).'?left=dev&right=prod');

        $this->assertContains('diff.viewed', DB::table('activity_log')->pluck('event')->all());
    }
}
