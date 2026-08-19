<?php

namespace Tests\Feature\SharedVault;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Pins\IssuePin;
use App\Actions\SharedVault\CreateSharedGroup;
use App\Actions\SharedVault\SaveSharedSecret;
use App\Enums\Permission;
use App\Models\Organization;
use App\Models\SharedGroup;
use App\Models\SharedSecret;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * The organization's shared vault: passwords, keys and files the team holds in
 * common.
 *
 * Three separate powers, because they are three separate risks: seeing that a
 * credential exists, reading it, and changing the set. Reading always costs a
 * PIN as well - there is no policy here that can waive it.
 */
class SharedVaultTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private const PIN = '4821';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');
    }

    private function seedSecret(string $name = 'Staging root password'): SharedSecret
    {
        return app(SaveSharedSecret::class)->secret(
            $this->organization,
            $this->owner,
            $name,
            'fake-root-password',
        );
    }

    /** A member holding exactly the listed permissions, plus a working PIN. */
    private function memberWith(Permission ...$permissions): User
    {
        $member = $this->joinMember($this->organization);

        if ($permissions !== []) {
            $this->grant($member, $permissions, $this->organization);
        }

        app(IssuePin::class)($this->organization, $this->owner, $member, self::PIN);

        return $member;
    }

    private function events(): array
    {
        return DB::table('activity_log')->pluck('event')->all();
    }

    // ── Storing ─────────────────────────────────────────────────────────────

    public function test_a_manager_adds_a_secret_and_it_is_encrypted_at_rest(): void
    {
        $secret = $this->seedSecret();

        $this->assertSame('fake-root-password', $secret->fresh()->value);

        // What actually sits in the column is ciphertext, not the password.
        $stored = (string) DB::table('shared_secrets')->where('id', $secret->id)->value('value');
        $this->assertStringNotContainsString('fake-root-password', $stored);
        $this->assertContains('shared.created', $this->events());
    }

    public function test_a_file_is_encrypted_before_it_reaches_the_disk(): void
    {
        $secret = app(SaveSharedSecret::class)->file(
            $this->organization,
            $this->owner,
            UploadedFile::fake()->createWithContent('deploy.pem', 'FAKE KEY MATERIAL'),
        );

        $disk = Storage::disk('local');

        $this->assertTrue($disk->exists($secret->storagePath()));
        $this->assertStringNotContainsString('FAKE KEY MATERIAL', (string) $disk->get($secret->storagePath()));
        $this->assertSame('deploy.pem', $secret->name);
    }

    public function test_adding_takes_the_manage_grant_and_the_refusal_is_audited(): void
    {
        $member = $this->memberWith(Permission::ViewSharedVault);

        $this->actingAs($member)
            ->post(route('shared.store', $this->organization), [
                'name' => 'Sneaky',
                'value' => 'nope',
            ])
            ->assertForbidden();

        $this->assertSame(0, SharedSecret::query()->count());
        $this->assertContains('shared.change-denied', $this->events());
    }

    public function test_an_item_can_be_renamed_and_regrouped_without_touching_its_value(): void
    {
        $secret = $this->seedSecret();
        $group = app(CreateSharedGroup::class)($this->organization, $this->owner, 'Staging server');

        $this->actingAs($this->owner)
            ->patch(route('shared.update', [$this->organization, $secret]), [
                'name' => 'Staging root',
                'shared_group_id' => $group->id,
            ])
            ->assertRedirect();

        $fresh = $secret->fresh();

        $this->assertSame('Staging root', $fresh->name);
        $this->assertSame($group->id, $fresh->shared_group_id);
        $this->assertSame('fake-root-password', $fresh->value);
    }

    public function test_a_group_from_another_organization_is_refused(): void
    {
        $other = app(CreateOrganization::class)(
            User::factory()->onboarded()->organizationCreator()->create(),
            'Theirs',
        );
        $foreign = SharedGroup::create(['organization_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($this->owner)
            ->post(route('shared.store', $this->organization), [
                'name' => 'Key',
                'value' => 'fake',
                'shared_group_id' => $foreign->id,
            ])
            ->assertSessionHasErrors('shared_group_id');
    }

    public function test_deleting_is_reversible_and_audited(): void
    {
        $secret = $this->seedSecret();

        $this->actingAs($this->owner)
            ->delete(route('shared.destroy', [$this->organization, $secret]))
            ->assertRedirect();

        $this->assertSoftDeleted('shared_secrets', ['id' => $secret->id]);
        $this->assertContains('shared.deleted', $this->events());
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    public function test_the_list_carries_metadata_and_never_a_value(): void
    {
        $this->seedSecret();

        $response = $this->actingAs($this->owner)
            ->get(route('organizations.show', $this->organization));

        $response->assertInertia(fn ($page) => $page
            ->where('shared.canView', true)
            ->where('shared.canReveal', true)
            ->where('shared.canManage', true)
            ->where('shared.items.0.name', 'Staging root password')
            ->where('shared.items.0.type', 'secret')
            ->etc());

        $this->assertStringNotContainsString('fake-root-password', $response->getContent() ?: '');
    }

    public function test_a_member_without_the_view_grant_is_shown_nothing(): void
    {
        $this->seedSecret();
        $member = $this->memberWith();

        $this->actingAs($member)
            ->get(route('organizations.show', $this->organization))
            ->assertInertia(fn ($page) => $page
                ->where('shared.canView', false)
                ->where('shared.items', [])
                ->etc());
    }

    public function test_revealing_returns_the_value_once_the_pin_is_right(): void
    {
        $secret = $this->seedSecret();
        $member = $this->memberWith(Permission::ViewSharedVault, Permission::RevealSharedVault);

        $this->actingAs($member)
            ->postJson(route('shared.reveal', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertOk()
            ->assertJson(['name' => 'Staging root password', 'value' => 'fake-root-password']);

        $this->assertContains('shared.revealed', $this->events());
    }

    // Reading a FILE back out - download, preview, and the refusal that keeps
    // a keystore out of a JSON body - lives in SharedVaultFilesTest.

    public function test_the_wrong_pin_is_refused_counted_and_audited(): void
    {
        $secret = $this->seedSecret();
        $member = $this->memberWith(Permission::ViewSharedVault, Permission::RevealSharedVault);

        $this->actingAs($member)
            ->postJson(route('shared.reveal', [$this->organization, $secret]), ['pin' => '0000'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'pin_incorrect', 'attempts_remaining' => 4]);

        $this->assertContains('pin.failed', $this->events());
    }

    public function test_five_wrong_pins_lock_the_vault_for_this_person(): void
    {
        $secret = $this->seedSecret();
        $member = $this->memberWith(Permission::ViewSharedVault, Permission::RevealSharedVault);

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($member)
                ->postJson(route('shared.reveal', [$this->organization, $secret]), ['pin' => '0000']);
        }

        // Even the right PIN is refused now.
        $this->actingAs($member)
            ->postJson(route('shared.reveal', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertStatus(429)
            ->assertJson(['reason' => 'locked']);

        $this->assertContains('shared.locked-out', $this->events());
    }

    public function test_seeing_the_vault_is_not_permission_to_read_it(): void
    {
        $secret = $this->seedSecret();
        $member = $this->memberWith(Permission::ViewSharedVault);

        $this->actingAs($member)
            ->postJson(route('shared.reveal', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertStatus(403)
            ->assertJson(['reason' => 'denied']);

        $this->assertContains('shared.reveal-denied', $this->events());
    }

    public function test_a_reveal_without_a_pin_is_refused_by_validation(): void
    {
        $secret = $this->seedSecret();
        $member = $this->memberWith(Permission::ViewSharedVault, Permission::RevealSharedVault);

        $this->actingAs($member)
            ->postJson(route('shared.reveal', [$this->organization, $secret]), [])
            ->assertStatus(422);
    }

    // ── Boundaries ──────────────────────────────────────────────────────────

    public function test_an_item_cannot_be_reached_through_another_organizations_url(): void
    {
        $secret = $this->seedSecret();
        $other = app(CreateOrganization::class)(
            User::factory()->onboarded()->organizationCreator()->create(),
            'Theirs',
        );
        $this->joinMember($other, $this->owner);

        $this->actingAs($this->owner)
            ->postJson(route('shared.reveal', [$other, $secret]), ['pin' => self::PIN])
            ->assertNotFound();
    }

    public function test_a_guest_is_turned_away_from_every_shared_route(): void
    {
        $secret = $this->seedSecret();

        $this->post(route('shared.store', $this->organization), ['name' => 'x', 'value' => 'y'])
            ->assertRedirect(route('login'));
        $this->delete(route('shared.destroy', [$this->organization, $secret]))
            ->assertRedirect(route('login'));
        $this->postJson(route('shared.reveal', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertUnauthorized();
    }

    public function test_deleting_the_organization_takes_its_shared_vault_with_it(): void
    {
        $secret = $this->seedSecret();

        $this->organization->forceDelete();

        $this->assertDatabaseMissing('shared_secrets', ['id' => $secret->id]);
    }

    public function test_two_groups_in_one_organization_cannot_share_a_name(): void
    {
        app(CreateSharedGroup::class)($this->organization, $this->owner, 'Staging server');

        $this->actingAs($this->owner)
            ->post(route('shared.groups.store', $this->organization), ['name' => 'staging SERVER'])
            ->assertSessionHasErrors('name');
    }

    public function test_the_creator_of_an_organization_starts_with_all_three_powers(): void
    {
        foreach ([Permission::ViewSharedVault, Permission::RevealSharedVault, Permission::ManageSharedVault] as $permission) {
            $this->assertDatabaseHas('grants', [
                'user_id' => $this->owner->id,
                'organization_id' => $this->organization->id,
                'permission' => $permission->value,
                'project_id' => null,
                'environment_id' => null,
            ]);
        }
    }
}
