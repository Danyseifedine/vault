<?php

namespace Tests\Feature\Http;

use App\Actions\Pins\IssuePin;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\SetVariableValue;
use App\Enums\Permission;
use App\Enums\PinStatus;
use App\Enums\Sensitivity;
use App\Models\Environment;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\PersonalSecret;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use App\Services\Access\AccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The routes that hand out, or refuse, secrets and authority.
 *
 * The password used throughout is the factory default - an obviously fake
 * value, as rule 11 requires.
 */
class SecurityEndpointsTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private const PASSWORD = 'password';

    private Organization $organization;

    private User $owner;

    private Project $project;

    private Environment $prod;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->organization->creator;
        $this->project = Project::factory()->for($this->organization)->create(['name' => 'API']);
        $this->prod = Environment::factory()->for($this->project)->create(['name' => 'prod']);
    }

    private function variableWithValue(
        string $key = 'DATABASE_URL',
        Sensitivity $sensitivity = Sensitivity::Sensitive,
        string $value = 'postgres://user:fakepass@localhost/db',
    ): Variable {
        $variable = app(CreateVariable::class)($this->project, $this->owner, $key, sensitivity: $sensitivity);
        app(SetVariableValue::class)($variable, $this->prod, $this->owner, $value);

        return $variable;
    }

    private function revealUrl(Variable $variable): string
    {
        return route('values.reveal', [$this->organization, $this->project, $variable, $this->prod]);
    }

    private function events(): array
    {
        return DB::table('activity_log')->pluck('event')->all();
    }

    // ── Reveal ──────────────────────────────────────────────────────────────

    public function test_a_public_value_is_revealed_without_a_pin(): void
    {
        $variable = $this->variableWithValue('APP_NAME', Sensitivity::Public, 'lebify');

        $this->actingAs($this->owner)
            ->postJson($this->revealUrl($variable))
            ->assertOk()
            ->assertJson(['key' => 'APP_NAME', 'value' => 'lebify']);

        $this->assertContains('variable.revealed', $this->events());
    }

    public function test_a_sensitive_value_demands_a_pin_first(): void
    {
        $variable = $this->variableWithValue();

        $this->actingAs($this->owner)
            ->postJson($this->revealUrl($variable))
            ->assertStatus(422)
            ->assertJson(['reason' => 'pin_required'])
            ->assertJsonMissingPath('value');
    }

    public function test_the_right_pin_reveals_the_value(): void
    {
        $variable = $this->variableWithValue();
        app(IssuePin::class)($this->organization, $this->owner, $this->owner, '1234');

        $this->actingAs($this->owner)
            ->postJson($this->revealUrl($variable), ['pin' => '1234'])
            ->assertOk()
            ->assertJson(['value' => 'postgres://user:fakepass@localhost/db']);
    }

    public function test_a_wrong_pin_is_refused_and_audited(): void
    {
        $variable = $this->variableWithValue();
        app(IssuePin::class)($this->organization, $this->owner, $this->owner, '1234');

        $this->actingAs($this->owner)
            ->postJson($this->revealUrl($variable), ['pin' => '9999'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'pin_incorrect']);

        $this->assertContains('pin.failed', $this->events());
    }

    public function test_a_critical_value_also_demands_the_account_password(): void
    {
        $variable = $this->variableWithValue('DATABASE_URL', Sensitivity::Critical);
        app(IssuePin::class)($this->organization, $this->owner, $this->owner, '1234');

        $this->actingAs($this->owner)
            ->postJson($this->revealUrl($variable), ['pin' => '1234'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'password_required']);

        $this->actingAs($this->owner)
            ->postJson($this->revealUrl($variable), ['pin' => '1234', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['value']);
    }

    public function test_a_blocked_pin_stops_working_immediately(): void
    {
        $variable = $this->variableWithValue();
        $pin = app(IssuePin::class)($this->organization, $this->owner, $this->owner, '1234');

        $this->actingAs($this->owner)
            ->patch(route('pins.update', [$this->organization, $pin]), ['status' => PinStatus::Blocked->value])
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->postJson($this->revealUrl($variable), ['pin' => '1234'])
            ->assertStatus(422);
    }

    public function test_someone_without_environment_access_is_refused_the_value(): void
    {
        $variable = $this->variableWithValue();

        $this->actingAs($this->joinMember($this->organization))
            ->postJson($this->revealUrl($variable))
            ->assertForbidden()
            ->assertJson(['reason' => 'denied']);

        $this->assertContains('reveal.denied', $this->events());
    }

    public function test_a_pin_must_be_four_digits(): void
    {
        $variable = $this->variableWithValue();

        $this->actingAs($this->owner)
            ->postJson($this->revealUrl($variable), ['pin' => 'abcdef'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');
    }

    public function test_a_guest_cannot_reveal_anything(): void
    {
        $variable = $this->variableWithValue();

        $this->postJson($this->revealUrl($variable))->assertUnauthorized();
    }

    // ── PINs ────────────────────────────────────────────────────────────────

    public function test_only_a_pins_manage_holder_issues_pins(): void
    {
        // Plenty of authority elsewhere, but not pins.manage - still refused.
        $powerful = $this->joinMember($this->organization);
        $this->grant($powerful, [Permission::ManageMembers, Permission::InviteMembers], $this->organization);
        $holder = $this->joinMember($this->organization);

        $this->actingAs($powerful)
            ->post(route('pins.store', $this->organization), ['user_id' => $holder->id, 'pin' => '1234'])
            ->assertForbidden();

        $issuer = $this->joinMember($this->organization);
        $this->grant($issuer, Permission::ManagePins, $this->organization);

        $this->actingAs($issuer)
            ->post(route('pins.store', $this->organization), ['user_id' => $holder->id, 'pin' => '1234'])
            ->assertRedirect();

        $this->assertDatabaseCount('pins', 1);
    }

    public function test_an_issued_pin_is_never_stored_or_returned_in_the_clear(): void
    {
        $holder = $this->joinMember($this->organization);

        $this->actingAs($this->owner)
            ->post(route('pins.store', $this->organization), ['user_id' => $holder->id, 'pin' => '4321']);

        $row = json_encode(DB::table('pins')->first());

        $this->assertStringNotContainsString('4321', (string) $row);
        $this->assertStringNotContainsString('4321', DB::table('activity_log')->pluck('properties')->implode(' '));
    }

    // ── Members ─────────────────────────────────────────────────────────────

    public function test_a_manager_grants_and_then_revokes_environment_access(): void
    {
        $member = $this->joinMember($this->organization);

        $this->actingAs($this->owner)
            ->put(route('members.grants', [$this->organization, $member]), [
                'grants' => [
                    ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->prod->id],
                ],
            ])
            ->assertRedirect();

        $this->assertTrue(app(AccessResolver::class)->can($member, Permission::ViewVariables, $this->prod));

        $this->actingAs($this->owner)
            ->put(route('members.grants', [$this->organization, $member]), ['grants' => []])
            ->assertRedirect();

        $this->assertFalse(app(AccessResolver::class)->can($member, Permission::ViewVariables, $this->prod));
        $this->assertSame(0, Grant::query()->where('user_id', $member->id)->count());
    }

    public function test_a_plain_member_cannot_grant_access(): void
    {
        $actor = $this->joinMember($this->organization);
        $target = $this->joinMember($this->organization);

        $this->actingAs($actor)
            ->put(route('members.grants', [$this->organization, $target]), [
                'grants' => [
                    ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->prod->id],
                ],
            ])
            ->assertForbidden();
    }

    public function test_the_last_manager_cannot_lose_members_manage_through_the_route(): void
    {
        // The creator is the only joined holder of org-wide members.manage.
        $this->actingAs($this->owner)
            ->put(route('members.grants', [$this->organization, $this->owner]), ['grants' => []])
            ->assertSessionHasErrors('grants');

        $second = $this->joinMember($this->organization);
        $this->grant($second, Permission::ManageMembers, $this->organization);

        // With a second joined manager, even the creator is revocable.
        $this->actingAs($this->owner)
            ->put(route('members.grants', [$this->organization, $this->owner]), ['grants' => []])
            ->assertRedirect();

        $this->assertSame(0, Grant::query()->where('user_id', $this->owner->id)->count());
    }

    public function test_an_invite_holder_invites_someone_and_the_account_is_reserved_inert(): void
    {
        $inviter = $this->joinMember($this->organization);
        $this->grant($inviter, Permission::InviteMembers, $this->organization);

        $this->actingAs($inviter)
            ->post(route('members.invite', $this->organization), [
                'email' => 'newcomer@lebify.test',
                'grants' => [
                    ['permission' => Permission::ViewVariables->value, 'environment_id' => $this->prod->id],
                ],
            ])
            ->assertRedirect();

        $invited = User::query()->where('email', 'newcomer@lebify.test')->firstOrFail();

        $this->assertTrue($invited->isInvited());
        $this->assertDatabaseHas('grants', [
            'user_id' => $invited->id,
            'environment_id' => $this->prod->id,
            'permission' => Permission::ViewVariables->value,
        ]);
    }

    public function test_a_plain_member_cannot_invite(): void
    {
        $this->actingAs($this->joinMember($this->organization))
            ->post(route('members.invite', $this->organization), [
                'email' => 'newcomer@lebify.test',
                'grants' => [],
            ])
            ->assertForbidden();
    }

    // ── Export & import ─────────────────────────────────────────────────────

    public function test_an_export_returns_a_file_and_is_audited(): void
    {
        $this->variableWithValue('DATABASE_URL', Sensitivity::Sensitive, 'postgres://fake');

        $response = $this->actingAs($this->owner)
            ->get(route('env.export', [$this->organization, $this->project, $this->prod]));

        $response->assertOk();
        $this->assertStringContainsString('DATABASE_URL=', $response->streamedContent());
        $this->assertContains('export.generated', $this->events());
    }

    public function test_a_viewer_without_export_rights_is_refused(): void
    {
        $this->variableWithValue();

        $viewer = $this->joinMember($this->organization);
        $this->grant($viewer, Permission::ViewVariables, $this->prod);

        $this->actingAs($viewer)
            ->get(route('env.export', [$this->organization, $this->project, $this->prod]))
            ->assertForbidden();
    }

    public function test_an_import_creates_variables_and_values(): void
    {
        $this->actingAs($this->owner)
            ->post(route('env.import', [$this->organization, $this->project, $this->prod]), [
                'contents' => "# comment\nDATABASE_URL=postgres://fake\nAPP_DEBUG=true\n",
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('variables', ['key' => 'DATABASE_URL']);
        $this->assertDatabaseHas('variables', ['key' => 'APP_DEBUG']);
        $this->assertContains('import.completed', $this->events());
    }

    public function test_an_import_needs_either_a_paste_or_a_file_but_not_both(): void
    {
        $this->actingAs($this->owner)
            ->post(route('env.import', [$this->organization, $this->project, $this->prod]), [])
            ->assertSessionHasErrors('contents');
    }

    public function test_a_reader_cannot_import(): void
    {
        $reader = $this->joinMember($this->organization);
        $this->grantReader($reader, $this->prod);

        $this->actingAs($reader)
            ->post(route('env.import', [$this->organization, $this->project, $this->prod]), [
                'contents' => 'DATABASE_URL=postgres://fake',
            ])
            ->assertForbidden();
    }

    // ── Personal vault ──────────────────────────────────────────────────────

    public function test_someone_elses_personal_item_is_out_of_reach(): void
    {
        $stranger = $this->joinMember($this->organization);

        $this->actingAs($this->owner)
            ->post(route('personal.secrets.store'), ['name' => 'GitHub', 'value' => 'ghp_fake_123'])
            ->assertRedirect();

        $item = PersonalSecret::query()->firstOrFail();

        $this->actingAs($stranger)
            ->postJson(route('personal.reveal', $item))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->delete(route('personal.destroy', $item))
            ->assertForbidden();
    }

    public function test_the_owner_reveals_their_own_item(): void
    {
        $this->actingAs($this->owner)
            ->post(route('personal.secrets.store'), ['name' => 'GitHub', 'value' => 'ghp_fake_123']);

        $item = PersonalSecret::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->postJson(route('personal.reveal', $item))
            ->assertOk()
            ->assertJson(['value' => 'ghp_fake_123']);
    }

    public function test_a_personal_secret_is_encrypted_at_rest(): void
    {
        $this->actingAs($this->owner)
            ->post(route('personal.secrets.store'), ['name' => 'GitHub', 'value' => 'ghp_fake_123']);

        $stored = (string) DB::table('personal_secrets')->value('value');

        $this->assertStringNotContainsString('ghp_fake_123', $stored);
        $this->assertStringNotContainsString(
            'ghp_fake_123',
            DB::table('activity_log')->pluck('properties')->implode(' '),
        );
    }
}
