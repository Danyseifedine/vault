<?php

namespace Tests\Feature\Personal;

use App\Actions\PersonalVault\CreatePersonalSecret;
use App\Actions\PersonalVault\RevealPersonalSecret;
use App\Actions\PersonalVault\StorePersonalFile;
use App\Actions\PersonalVault\UpdatePersonalItem;
use App\Models\PersonalSecret;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Changing the value of a personal secret.
 *
 * The vault used to refuse this outright: an edit could rename and refile but
 * never rotate. That made the personal vault the odd one out - the shared vault
 * has always allowed it - and left rotating a token as "delete it and add it
 * again", which loses the note and the folder along the way.
 *
 * The rule that keeps a rename from eating a secret is the one the shared vault
 * uses: a blank value means "leave the stored one alone". Only what you typed
 * can overwrite anything, and a file's contents are never touched by an edit.
 */
class UpdatePersonalValueTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default', 'local'));

        $this->owner = User::factory()->onboarded()->create();
        $this->stranger = User::factory()->onboarded()->create();
    }

    private function secret(string $value = 'sk_test_fake_original'): PersonalSecret
    {
        return app(CreatePersonalSecret::class)(
            $this->owner,
            'GitHub token',
            $value,
            null,
            'the one for CI',
        );
    }

    private function update(PersonalSecret $item, ?string $value, ?User $actor = null): PersonalSecret
    {
        return app(UpdatePersonalItem::class)(
            $item,
            $actor ?? $this->owner,
            $item->name,
            null,
            $item->description,
            $value,
        );
    }

    private function valueOf(PersonalSecret $item): string
    {
        return app(RevealPersonalSecret::class)($item->fresh(), $this->owner);
    }

    /** @return array<int, string> */
    private function events(): array
    {
        return DB::table('activity_log')->pluck('event')->all();
    }

    // ── Rotating ────────────────────────────────────────────────────────────

    public function test_the_owner_can_replace_the_stored_value(): void
    {
        $item = $this->secret();

        $this->update($item, 'sk_test_fake_rotated');

        $this->assertSame('sk_test_fake_rotated', $this->valueOf($item));
    }

    public function test_the_new_value_is_encrypted_at_rest(): void
    {
        $item = $this->secret();

        $this->update($item, 'sk_test_fake_rotated');

        $stored = DB::table('personal_secrets')->where('id', $item->id)->value('value');

        $this->assertNotSame('sk_test_fake_rotated', $stored);
        $this->assertStringNotContainsString('sk_test_fake_rotated', (string) $stored);
    }

    public function test_a_blank_value_keeps_the_one_already_stored(): void
    {
        $item = $this->secret();

        $this->update($item, '');
        $this->assertSame('sk_test_fake_original', $this->valueOf($item));

        $this->update($item, null);
        $this->assertSame('sk_test_fake_original', $this->valueOf($item));
    }

    public function test_renaming_alone_never_touches_the_value(): void
    {
        $item = $this->secret();

        app(UpdatePersonalItem::class)(
            $item,
            $this->owner,
            'Renamed token',
            null,
            'still the CI one',
        );

        $this->assertSame('sk_test_fake_original', $this->valueOf($item));
        $this->assertSame('Renamed token', $item->fresh()->name);
    }

    public function test_a_files_contents_are_never_replaced_by_an_edit(): void
    {
        $file = app(StorePersonalFile::class)(
            $this->owner,
            UploadedFile::fake()->createWithContent('deploy.pem', 'FAKE KEY MATERIAL'),
        );

        $this->update($file, 'sk_test_fake_not_a_file');

        $this->assertSame('FAKE KEY MATERIAL', $this->valueOf($file));
    }

    // ── The trail ───────────────────────────────────────────────────────────

    public function test_a_rotation_is_recorded_as_its_own_kind_of_change(): void
    {
        $item = $this->secret();

        $this->update($item, 'sk_test_fake_rotated');

        // "When did I last rotate this?" should be answerable at a glance.
        $this->assertContains('personal.value-changed', $this->events());
    }

    public function test_a_metadata_only_edit_is_still_an_ordinary_update(): void
    {
        $item = $this->secret();

        app(UpdatePersonalItem::class)($item, $this->owner, 'Renamed', null, null);

        $this->assertContains('personal.updated', $this->events());
        $this->assertNotContains('personal.value-changed', $this->events());
    }

    public function test_the_new_value_never_reaches_the_audit_properties(): void
    {
        $item = $this->secret();

        $this->update($item, 'sk_test_fake_rotated');

        $properties = DB::table('activity_log')->pluck('properties')->implode(' ');

        $this->assertStringNotContainsString('sk_test_fake_rotated', $properties);
        $this->assertStringNotContainsString('sk_test_fake_original', $properties);
    }

    public function test_the_entry_stays_in_the_private_trail(): void
    {
        $item = $this->secret();

        $this->update($item, 'sk_test_fake_rotated');

        $entry = DB::table('activity_log')->where('event', 'personal.value-changed')->first();

        // Personal activity never carries an organization or a project, which
        // is what keeps it out of every org feed.
        $this->assertNull($entry->organization_id);
        $this->assertNull($entry->project_id);
    }

    // ── Ownership and validation ────────────────────────────────────────────

    public function test_a_stranger_cannot_rotate_someone_elses_secret(): void
    {
        $item = $this->secret();

        try {
            $this->update($item, 'sk_test_fake_stolen', $this->stranger);
            $this->fail('a stranger must not be able to rewrite a personal secret');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame('sk_test_fake_original', $this->valueOf($item));
    }

    // ── Over HTTP ───────────────────────────────────────────────────────────

    public function test_the_endpoint_rotates_the_value(): void
    {
        $item = $this->secret();

        $this->actingAs($this->owner)
            ->patch(route('personal.update', $item), [
                'name' => 'GitHub token',
                'value' => 'sk_test_fake_rotated',
                'description' => 'the one for CI',
            ])
            ->assertRedirect();

        $this->assertSame('sk_test_fake_rotated', $this->valueOf($item));
    }

    public function test_the_endpoint_leaves_the_value_alone_when_none_is_sent(): void
    {
        $item = $this->secret();

        $this->actingAs($this->owner)
            ->patch(route('personal.update', $item), ['name' => 'Renamed'])
            ->assertRedirect();

        $this->assertSame('sk_test_fake_original', $this->valueOf($item));
    }

    public function test_an_oversized_value_is_refused(): void
    {
        $item = $this->secret();

        $this->actingAs($this->owner)
            ->patchJson(route('personal.update', $item), [
                'name' => 'GitHub token',
                'value' => str_repeat('x', 20001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');

        $this->assertSame('sk_test_fake_original', $this->valueOf($item));
    }

    public function test_a_guest_cannot_rotate_anything(): void
    {
        $item = $this->secret();

        $this->patch(route('personal.update', $item), [
            'name' => 'GitHub token',
            'value' => 'sk_test_fake_stolen',
        ])->assertRedirect(route('login'));

        $this->assertSame('sk_test_fake_original', $this->valueOf($item));
    }
}
