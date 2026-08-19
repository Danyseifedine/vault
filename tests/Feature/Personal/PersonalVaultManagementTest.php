<?php

namespace Tests\Feature\Personal;

use App\Actions\PersonalVault\CreatePersonalGroup;
use App\Actions\PersonalVault\CreatePersonalSecret;
use App\Actions\PersonalVault\DeletePersonalItem;
use App\Actions\PersonalVault\StorePersonalFile;
use App\Enums\SecretItemType;
use App\Models\PersonalSecret;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Creating, organizing and removing personal items.
 *
 * The rule that matters throughout: there is no administrative escape hatch.
 * Another user - including an organization owner - is a stranger here.
 */
class PersonalVaultManagementTest extends TestCase
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

    private function events(): array
    {
        return DB::table('activity_log')->pluck('event')->all();
    }

    public function test_a_secret_is_stored_encrypted_and_audited_privately(): void
    {
        $secret = app(CreatePersonalSecret::class)($this->owner, 'GitHub token', 'ghp_fake_token_123');

        $raw = DB::table('personal_secrets')->where('id', $secret->id)->value('value');

        $this->assertStringNotContainsString('ghp_fake_token_123', (string) $raw);
        $this->assertSame(SecretItemType::Secret, $secret->type);
        $this->assertContains('personal.secret-created', $this->events());

        $entry = DB::table('activity_log')->where('event', 'personal.secret-created')->first();
        $this->assertNull($entry->organization_id);
        $this->assertNull($entry->project_id);
    }

    public function test_the_plain_value_never_reaches_the_audit_properties(): void
    {
        app(CreatePersonalSecret::class)($this->owner, 'GitHub token', 'ghp_fake_token_123');

        $properties = DB::table('activity_log')->pluck('properties')->implode(' ');

        $this->assertStringNotContainsString('ghp_fake_token_123', $properties);
    }

    public function test_a_secret_needs_a_name_and_a_value(): void
    {
        $this->expectException(ValidationException::class);

        app(CreatePersonalSecret::class)($this->owner, '  ', 'something');
    }

    public function test_groups_are_created_per_owner_and_may_share_names_across_people(): void
    {
        $mine = app(CreatePersonalGroup::class)($this->owner, 'Work');
        $theirs = app(CreatePersonalGroup::class)($this->stranger, 'Work');

        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertContains('personal.group-created', $this->events());
    }

    public function test_one_person_cannot_have_two_groups_of_the_same_name(): void
    {
        app(CreatePersonalGroup::class)($this->owner, 'Work');

        $this->expectException(ValidationException::class);

        app(CreatePersonalGroup::class)($this->owner, 'work');
    }

    public function test_a_group_holds_both_secrets_and_files(): void
    {
        $group = app(CreatePersonalGroup::class)($this->owner, 'Work');

        app(CreatePersonalSecret::class)($this->owner, 'Token', 'fake', $group->id);
        app(StorePersonalFile::class)($this->owner, UploadedFile::fake()->create('key.pem', 4), null, $group->id);

        $types = $group->secrets()->pluck('type')->all();

        $this->assertEqualsCanonicalizing(
            [SecretItemType::Secret, SecretItemType::File],
            $types,
        );
    }

    public function test_an_item_cannot_be_filed_into_someone_elses_group(): void
    {
        $theirs = app(CreatePersonalGroup::class)($this->stranger, 'Theirs');

        $this->expectException(ValidationException::class);

        app(CreatePersonalSecret::class)($this->owner, 'Token', 'fake', $theirs->id);
    }

    // ── Deleting ────────────────────────────────────────────────────────────

    public function test_the_owner_deletes_their_own_item(): void
    {
        $secret = app(CreatePersonalSecret::class)($this->owner, 'Token', 'fake');

        app(DeletePersonalItem::class)($secret, $this->owner);

        $this->assertSoftDeleted('personal_secrets', ['id' => $secret->id]);
        $this->assertContains('personal.deleted', $this->events());
    }

    public function test_nobody_else_can_delete_an_item_and_the_attempt_is_recorded(): void
    {
        $secret = app(CreatePersonalSecret::class)($this->owner, 'Token', 'fake');

        try {
            app(DeletePersonalItem::class)($secret, $this->stranger);
            $this->fail('A stranger deleted someone else\'s personal item.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertDatabaseHas('personal_secrets', ['id' => $secret->id, 'deleted_at' => null]);
        $this->assertContains('personal.access-denied', $this->events());
    }

    public function test_deleting_a_file_removes_its_encrypted_bytes_from_disk(): void
    {
        $file = app(StorePersonalFile::class)($this->owner, UploadedFile::fake()->create('key.pem', 4));
        $path = $file->storagePath();

        Storage::disk(config('filesystems.default', 'local'))->assertExists($path);

        app(DeletePersonalItem::class)($file, $this->owner);

        Storage::disk(config('filesystems.default', 'local'))->assertMissing($path);
    }

    public function test_deleting_a_group_keeps_its_items_but_ungroups_them(): void
    {
        $group = app(CreatePersonalGroup::class)($this->owner, 'Work');
        $secret = app(CreatePersonalSecret::class)($this->owner, 'Token', 'fake', $group->id);

        app(DeletePersonalItem::class)->group($group, $this->owner);

        $this->assertDatabaseMissing('personal_groups', ['id' => $group->id]);
        $this->assertDatabaseHas('personal_secrets', ['id' => $secret->id]);
        $this->assertNull($secret->fresh()->personal_group_id);
    }

    public function test_a_stranger_cannot_delete_a_group(): void
    {
        $group = app(CreatePersonalGroup::class)($this->owner, 'Work');

        $this->expectException(AuthorizationException::class);

        app(DeletePersonalItem::class)->group($group, $this->stranger);
    }

    // ── Uploading a file with its own label ─────────────────────────────────

    public function test_an_uploaded_file_keeps_the_name_and_note_it_was_given(): void
    {
        $this->actingAs($this->owner)
            ->post(route('personal.files.store'), [
                'file' => UploadedFile::fake()->createWithContent('id_rsa', 'FAKE KEY MATERIAL'),
                'name' => 'VPS deploy key',
                'description' => 'root@staging, rotate in June',
            ])
            ->assertRedirect();

        $item = PersonalSecret::query()->ownedBy($this->owner)->sole();

        $this->assertSame('VPS deploy key', $item->name);
        $this->assertSame('root@staging, rotate in June', $item->description);
    }

    public function test_an_unnamed_upload_falls_back_to_the_filename(): void
    {
        $this->actingAs($this->owner)
            ->post(route('personal.files.store'), [
                'file' => UploadedFile::fake()->createWithContent('deploy.pem', 'FAKE KEY MATERIAL'),
            ])
            ->assertRedirect();

        $this->assertSame('deploy.pem', PersonalSecret::query()->ownedBy($this->owner)->sole()->name);
    }

    public function test_the_note_is_optional(): void
    {
        $this->actingAs($this->owner)
            ->post(route('personal.files.store'), [
                'file' => UploadedFile::fake()->createWithContent('deploy.pem', 'FAKE KEY MATERIAL'),
                'name' => 'Deploy key',
            ])
            ->assertRedirect();

        $this->assertNull(PersonalSecret::query()->ownedBy($this->owner)->sole()->description);
    }

    public function test_an_oversized_name_or_note_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('personal.files.store'), [
                'file' => UploadedFile::fake()->createWithContent('deploy.pem', 'FAKE'),
                'name' => str_repeat('x', 121),
                'description' => str_repeat('y', 1001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'description']);

        $this->assertSame(0, PersonalSecret::query()->ownedBy($this->owner)->count());
    }

    public function test_the_owned_scope_is_what_separates_two_peoples_vaults(): void
    {
        app(CreatePersonalSecret::class)($this->owner, 'Mine', 'fake');
        app(CreatePersonalSecret::class)($this->stranger, 'Theirs', 'fake');

        $this->assertSame(1, PersonalSecret::query()->ownedBy($this->owner)->count());
        $this->assertSame(
            ['Mine'],
            PersonalSecret::query()->ownedBy($this->owner)->pluck('name')->all(),
        );
    }
}
