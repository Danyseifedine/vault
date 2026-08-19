<?php

namespace Tests\Feature\Personal;

use App\Actions\PersonalVault\RevealPersonalSecret;
use App\Actions\PersonalVault\StorePersonalFile;
use App\Enums\SecretItemType;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PersonalGroup;
use App\Models\PersonalSecret;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

class PersonalVaultTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    private User $owner;

    private User $orgCreator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $organization = Organization::factory()->create();
        $this->orgCreator = $organization->creator;

        $this->owner = $this->joinMember($organization);
    }

    private function secret(string $name = 'GitHub token', string $value = 'ghp_fake_token'): PersonalSecret
    {
        return PersonalSecret::create([
            'user_id' => $this->owner->id,
            'type' => SecretItemType::Secret,
            'name' => $name,
            'value' => $value,
        ]);
    }

    public function test_a_personal_secret_is_encrypted_at_rest(): void
    {
        $this->secret(value: 'ghp_fake_token');

        $stored = DB::table('personal_secrets')->latest('id')->value('value');

        $this->assertStringNotContainsString('ghp_fake_token', $stored);
    }

    public function test_the_owner_can_reveal_their_own_item(): void
    {
        $secret = $this->secret();

        $revealed = app(RevealPersonalSecret::class)($secret, $this->owner);

        $this->assertSame('ghp_fake_token', $revealed);
        $this->assertDatabaseHas('activity_log', ['event' => 'personal.revealed']);
    }

    /** The creator holds every organization permission - none of them reach into a personal vault. */
    public function test_even_the_organizations_creator_cannot_reveal_someone_elses_item(): void
    {
        $secret = $this->secret();

        $this->expectException(AuthorizationException::class);

        try {
            app(RevealPersonalSecret::class)($secret, $this->orgCreator);
        } finally {
            $this->assertDatabaseHas('activity_log', ['event' => 'personal.access-denied']);
        }
    }

    public function test_personal_activity_never_leaks_into_an_organization_feed(): void
    {
        app(RevealPersonalSecret::class)($this->secret(), $this->owner);

        $entry = AuditLog::query()->where('event', 'personal.revealed')->first();

        // No organization or project scope means it cannot surface in any
        // organization or project feed - those queries filter on these ids.
        $this->assertNull($entry->organization_id);
        $this->assertNull($entry->project_id);
        $this->assertSame(1, AuditLog::query()->personal()->where('event', 'personal.revealed')->count());
    }

    public function test_an_uploaded_file_is_encrypted_before_it_reaches_the_disk(): void
    {
        $file = UploadedFile::fake()->createWithContent('server.pem', 'PRIVATE-KEY-CONTENTS');

        $secret = app(StorePersonalFile::class)($this->owner, $file);

        $onDisk = Storage::disk('local')->get($secret->storagePath());

        $this->assertStringNotContainsString('PRIVATE-KEY-CONTENTS', $onDisk);
        $this->assertSame('PRIVATE-KEY-CONTENTS', app(RevealPersonalSecret::class)($secret, $this->owner));
    }

    public function test_downloading_someone_elses_file_is_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('server.pem', 'PRIVATE-KEY-CONTENTS');
        $secret = app(StorePersonalFile::class)($this->owner, $file);

        $this->expectException(AuthorizationException::class);

        app(RevealPersonalSecret::class)($secret, $this->orgCreator);
    }

    public function test_a_group_can_mix_secrets_and_files(): void
    {
        $group = PersonalGroup::create(['user_id' => $this->owner->id, 'name' => 'my VPS']);

        PersonalSecret::create([
            'user_id' => $this->owner->id,
            'personal_group_id' => $group->id,
            'type' => SecretItemType::Secret,
            'name' => 'root password',
            'value' => 'fake-root-password',
        ]);

        app(StorePersonalFile::class)(
            $this->owner,
            UploadedFile::fake()->createWithContent('vps.pem', 'KEY'),
            groupId: $group->id,
        );

        $types = $group->secrets()->get()->pluck('type');

        $this->assertCount(2, $types);
        $this->assertTrue($types->contains(SecretItemType::Secret));
        $this->assertTrue($types->contains(SecretItemType::File));
    }

    public function test_the_owned_scope_is_the_boundary(): void
    {
        $this->secret();
        PersonalSecret::create([
            'user_id' => $this->orgCreator->id,
            'type' => SecretItemType::Secret,
            'name' => 'their own thing',
            'value' => 'not-yours',
        ]);

        $this->assertSame(1, PersonalSecret::query()->ownedBy($this->owner)->count());
        $this->assertSame(2, PersonalSecret::query()->count());
    }
}
