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
 * Reading a shared FILE back out, and removing a shared group.
 *
 * A file's bytes cannot ride a JSON reveal - a keystore is not UTF-8 and
 * json_encode refuses it - so a file is read through `download` (streamed) or
 * `preview` (a bounded look). Both cost a PIN like every other shared read,
 * both are audited, and both count attempts on the same counter.
 */
class SharedVaultFilesTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private const PIN = '4821';

    /** A DER keystore: legal bytes, illegal UTF-8. The regression that started this. */
    private const BINARY = "\x30\x82\x04\xA3\x02\x01\x00\xFF\xFE\x00\x80\x9Ffake-keystore";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->owner = User::factory()->onboarded()->organizationCreator()->create();
        $this->organization = app(CreateOrganization::class)($this->owner, 'Acme');

        app(IssuePin::class)($this->organization, $this->owner, $this->owner, self::PIN);
    }

    private function seedFile(string $name = 'keystore.p12', string $contents = self::BINARY): SharedSecret
    {
        return app(SaveSharedSecret::class)->file(
            $this->organization,
            $this->owner,
            UploadedFile::fake()->createWithContent($name, $contents),
        );
    }

    private function seedSecret(): SharedSecret
    {
        return app(SaveSharedSecret::class)->secret(
            $this->organization,
            $this->owner,
            'Staging root password',
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

    /** @return array<int, string> */
    private function events(): array
    {
        return DB::table('activity_log')->pluck('event')->all();
    }

    // ── Download ────────────────────────────────────────────────────────────

    public function test_a_binary_file_downloads_byte_for_byte(): void
    {
        $secret = $this->seedFile();

        $response = $this->actingAs($this->owner)
            ->post(route('shared.download', [$this->organization, $secret]), ['pin' => self::PIN]);

        $response->assertOk();
        $this->assertSame(self::BINARY, $response->streamedContent());
        $response->assertDownload('keystore.p12');
    }

    public function test_a_text_file_downloads_intact(): void
    {
        $secret = $this->seedFile('deploy.pem', 'FAKE KEY MATERIAL');

        $response = $this->actingAs($this->owner)
            ->post(route('shared.download', [$this->organization, $secret]), ['pin' => self::PIN]);

        $response->assertOk();
        $this->assertSame('FAKE KEY MATERIAL', $response->streamedContent());
    }

    public function test_a_download_is_audited_as_a_reveal(): void
    {
        $secret = $this->seedFile();

        $this->actingAs($this->owner)
            ->post(route('shared.download', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertOk();

        $this->assertContains('shared.revealed', $this->events());
    }

    public function test_a_download_without_a_pin_is_refused_by_validation(): void
    {
        $secret = $this->seedFile();

        $this->actingAs($this->owner)
            ->postJson(route('shared.download', [$this->organization, $secret]), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');
    }

    public function test_a_download_with_the_wrong_pin_is_counted_and_audited(): void
    {
        $secret = $this->seedFile();

        $this->actingAs($this->owner)
            ->postJson(route('shared.download', [$this->organization, $secret]), ['pin' => '0000'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'pin_incorrect', 'attempts_remaining' => 4]);

        $this->assertContains('pin.failed', $this->events());
    }

    public function test_downloading_takes_the_reveal_grant_and_the_refusal_is_audited(): void
    {
        $secret = $this->seedFile();
        $member = $this->memberWith(Permission::ViewSharedVault);

        $this->actingAs($member)
            ->postJson(route('shared.download', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertStatus(403)
            ->assertJson(['reason' => 'denied']);

        $this->assertContains('shared.reveal-denied', $this->events());
    }

    public function test_five_wrong_pins_lock_downloading_for_this_person(): void
    {
        $secret = $this->seedFile();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($this->owner)
                ->postJson(route('shared.download', [$this->organization, $secret]), ['pin' => '0000']);
        }

        $this->actingAs($this->owner)
            ->postJson(route('shared.download', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertStatus(429)
            ->assertJson(['reason' => 'locked']);

        $this->assertContains('shared.locked-out', $this->events());
    }

    public function test_a_secret_item_cannot_be_downloaded(): void
    {
        $secret = $this->seedSecret();

        $this->actingAs($this->owner)
            ->postJson(route('shared.download', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertStatus(404);
    }

    /**
     * The `shared` route binder resolves an item through its organization, so
     * a foreign id is never found in the first place - the same 404 the JSON
     * reveal gives, rather than a 403 that would confirm the item exists.
     */
    public function test_a_file_cannot_be_downloaded_through_another_organizations_url(): void
    {
        $secret = $this->seedFile();

        $stranger = User::factory()->onboarded()->organizationCreator()->create();
        $other = app(CreateOrganization::class)($stranger, 'Other');

        $this->actingAs($stranger)
            ->postJson(route('shared.download', [$other, $secret]), ['pin' => self::PIN])
            ->assertNotFound();

        $this->actingAs($stranger)
            ->postJson(route('shared.preview', [$other, $secret]), ['pin' => self::PIN])
            ->assertNotFound();
    }

    public function test_a_guest_cannot_download(): void
    {
        $secret = $this->seedFile();

        $this->post(route('shared.download', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertRedirect(route('login'));
    }

    // ── Preview ─────────────────────────────────────────────────────────────

    public function test_a_text_file_previews_as_text(): void
    {
        $secret = $this->seedFile('notes.txt', "line one\nline two");

        $this->actingAs($this->owner)
            ->postJson(route('shared.preview', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertOk()
            ->assertJson(['kind' => 'text', 'contents' => "line one\nline two", 'truncated' => false]);
    }

    public function test_a_binary_file_previews_as_binary_rather_than_dumping_bytes(): void
    {
        $secret = $this->seedFile();

        $this->actingAs($this->owner)
            ->postJson(route('shared.preview', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertOk()
            ->assertJson(['kind' => 'binary', 'reason' => 'not-text']);
    }

    public function test_an_image_file_previews_as_an_image(): void
    {
        $png = "\x89PNG\r\n\x1a\n".'fake-png-body';
        $secret = $this->seedFile('logo.png', $png);

        $response = $this->actingAs($this->owner)
            ->postJson(route('shared.preview', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertOk()
            ->assertJson(['kind' => 'image']);

        $this->assertStringStartsWith('data:image/png;base64,', $response->json('dataUri'));
    }

    public function test_a_preview_is_audited_as_a_reveal(): void
    {
        $secret = $this->seedFile('notes.txt', 'hello');

        $this->actingAs($this->owner)
            ->postJson(route('shared.preview', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertOk();

        $this->assertContains('shared.revealed', $this->events());
    }

    public function test_a_preview_without_a_pin_is_refused(): void
    {
        $secret = $this->seedFile();

        $this->actingAs($this->owner)
            ->postJson(route('shared.preview', [$this->organization, $secret]), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');
    }

    public function test_a_preview_with_the_wrong_pin_is_counted_and_audited(): void
    {
        $secret = $this->seedFile();

        $this->actingAs($this->owner)
            ->postJson(route('shared.preview', [$this->organization, $secret]), ['pin' => '0000'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'pin_incorrect']);

        $this->assertContains('pin.failed', $this->events());
    }

    public function test_previewing_takes_the_reveal_grant(): void
    {
        $secret = $this->seedFile();
        $member = $this->memberWith(Permission::ViewSharedVault);

        $this->actingAs($member)
            ->postJson(route('shared.preview', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertStatus(403);

        $this->assertContains('shared.reveal-denied', $this->events());
    }

    public function test_a_secret_item_cannot_be_previewed(): void
    {
        $secret = $this->seedSecret();

        $this->actingAs($this->owner)
            ->postJson(route('shared.preview', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertStatus(404);
    }

    public function test_a_guest_cannot_preview(): void
    {
        $secret = $this->seedFile();

        $this->postJson(route('shared.preview', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertStatus(401);
    }

    // ── The JSON reveal refuses files rather than dying on them ─────────────

    public function test_a_file_is_refused_by_the_json_reveal_instead_of_breaking_it(): void
    {
        $secret = $this->seedFile();

        $this->actingAs($this->owner)
            ->postJson(route('shared.reveal', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertStatus(422)
            ->assertJson(['reason' => 'file_item']);
    }

    public function test_refusing_a_file_does_not_burn_a_pin_attempt(): void
    {
        $file = $this->seedFile();
        $secret = $this->seedSecret();

        $this->actingAs($this->owner)
            ->postJson(route('shared.reveal', [$this->organization, $file]), ['pin' => self::PIN])
            ->assertStatus(422);

        // The counter is untouched, so a correct PIN still works right after.
        $this->actingAs($this->owner)
            ->postJson(route('shared.reveal', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertOk()
            ->assertJson(['value' => 'fake-root-password']);
    }

    public function test_someone_without_reveal_is_denied_rather_than_told_it_is_a_file(): void
    {
        $secret = $this->seedFile();
        $member = $this->memberWith(Permission::ViewSharedVault);

        $this->actingAs($member)
            ->postJson(route('shared.reveal', [$this->organization, $secret]), ['pin' => self::PIN])
            ->assertStatus(403)
            ->assertJson(['reason' => 'denied']);
    }

    // ── Removing a group ────────────────────────────────────────────────────

    public function test_deleting_a_group_keeps_its_items_and_ungroups_them(): void
    {
        $group = app(CreateSharedGroup::class)($this->organization, $this->owner, 'Staging server');

        $secret = app(SaveSharedSecret::class)->secret(
            $this->organization,
            $this->owner,
            'Staging root password',
            'fake-root-password',
            $group->id,
        );

        $this->actingAs($this->owner)
            ->delete(route('shared.groups.destroy', [$this->organization, $group]))
            ->assertRedirect();

        $this->assertNull(SharedGroup::find($group->id));
        $this->assertNotNull($secret->fresh());
        $this->assertNull($secret->fresh()->shared_group_id);
        $this->assertContains('shared-group.deleted', $this->events());
    }

    public function test_deleting_a_group_takes_the_manage_grant_and_is_audited(): void
    {
        $group = app(CreateSharedGroup::class)($this->organization, $this->owner, 'Staging server');
        $member = $this->memberWith(Permission::ViewSharedVault, Permission::RevealSharedVault);

        $this->actingAs($member)
            ->delete(route('shared.groups.destroy', [$this->organization, $group]))
            ->assertForbidden();

        $this->assertNotNull(SharedGroup::find($group->id));
        $this->assertContains('shared.change-denied', $this->events());
    }

    public function test_a_group_cannot_be_deleted_through_another_organizations_url(): void
    {
        $group = app(CreateSharedGroup::class)($this->organization, $this->owner, 'Staging server');

        $stranger = User::factory()->onboarded()->organizationCreator()->create();
        $other = app(CreateOrganization::class)($stranger, 'Other');

        $this->actingAs($stranger)
            ->delete(route('shared.groups.destroy', [$other, $group]))
            ->assertForbidden();

        $this->assertNotNull(SharedGroup::find($group->id));
    }

    public function test_a_guest_cannot_delete_a_group(): void
    {
        $group = app(CreateSharedGroup::class)($this->organization, $this->owner, 'Staging server');

        $this->delete(route('shared.groups.destroy', [$this->organization, $group]))
            ->assertRedirect(route('login'));

        $this->assertNotNull(SharedGroup::find($group->id));
    }
}
