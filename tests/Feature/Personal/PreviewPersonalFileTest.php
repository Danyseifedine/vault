<?php

namespace Tests\Feature\Personal;

use App\Actions\PersonalVault\StorePersonalFile;
use App\Enums\SecretItemType;
use App\Models\PersonalSecret;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Looking inside a stored file without downloading it.
 *
 * A preview decrypts, which makes it a reveal in every sense that matters: the
 * plaintext leaves the server. So it is owner-only, audited, and it never
 * returns more of the file than it says it does.
 */
class PreviewPersonalFileTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->owner = User::factory()->onboarded()->create();
        $this->stranger = User::factory()->onboarded()->create();
    }

    private function storeFile(string $name, string $contents, ?User $owner = null): PersonalSecret
    {
        return app(StorePersonalFile::class)(
            $owner ?? $this->owner,
            UploadedFile::fake()->createWithContent($name, $contents),
        );
    }

    public function test_the_owner_sees_the_contents_of_their_own_text_file(): void
    {
        $item = $this->storeFile('.env.production', "APP_KEY=base64:fake\nDEBUG=false");

        $this->actingAs($this->owner)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertOk()
            ->assertJson([
                'kind' => 'text',
                'contents' => "APP_KEY=base64:fake\nDEBUG=false",
                'truncated' => false,
            ]);
    }

    public function test_a_long_file_is_cut_off_and_says_so(): void
    {
        $item = $this->storeFile('big.log', str_repeat('a', 200_000));

        $response = $this->actingAs($this->owner)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertOk()
            ->assertJson(['kind' => 'text', 'truncated' => true]);

        $this->assertSame(131_072, strlen($response->json('contents')));
    }

    public function test_an_image_comes_back_as_a_data_uri(): void
    {
        // A real 1x1 PNG: the detection reads magic bytes, not the file name.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $item = $this->storeFile('recovery.png', (string) $png);

        $this->actingAs($this->owner)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertOk()
            ->assertJsonPath('kind', 'image')
            ->assertJsonStructure(['kind', 'dataUri']);
    }

    public function test_a_binary_file_is_reported_rather_than_dumped(): void
    {
        $item = $this->storeFile('archive.bin', "PK\x03\x04\x00\x00binary\x00payload");

        $this->actingAs($this->owner)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertOk()
            ->assertJson(['kind' => 'binary'])
            ->assertJsonMissing(['contents' => 'binary']);
    }

    public function test_an_empty_file_previews_as_empty_text(): void
    {
        $item = $this->storeFile('blank.txt', '');

        $this->actingAs($this->owner)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertOk()
            ->assertJson(['kind' => 'text', 'contents' => '', 'truncated' => false]);
    }

    public function test_unicode_survives_the_round_trip(): void
    {
        $item = $this->storeFile('notes.txt', "clé = valeur ✓\nمفتاح");

        $this->actingAs($this->owner)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertOk()
            ->assertJson(['kind' => 'text', 'contents' => "clé = valeur ✓\nمفتاح"]);
    }

    public function test_a_secret_is_not_a_file_and_has_no_preview(): void
    {
        $item = PersonalSecret::create([
            'user_id' => $this->owner->id,
            'type' => SecretItemType::Secret,
            'name' => 'GitHub token',
            'value' => 'ghp_fake_token',
        ]);

        $this->actingAs($this->owner)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertNotFound();
    }

    public function test_nobody_else_can_preview_a_file_they_do_not_own(): void
    {
        $item = $this->storeFile('server.pem', 'PRIVATE-KEY-CONTENTS');

        $this->actingAs($this->stranger)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertForbidden();

        $this->assertDatabaseHas('activity_log', ['event' => 'personal.access-denied']);
    }

    public function test_a_refused_preview_never_leaks_the_contents(): void
    {
        $item = $this->storeFile('server.pem', 'PRIVATE-KEY-CONTENTS');

        $response = $this->actingAs($this->stranger)
            ->postJson("/personal/items/{$item->id}/preview");

        $this->assertStringNotContainsString('PRIVATE-KEY-CONTENTS', $response->getContent() ?: '');
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $item = $this->storeFile('server.pem', 'PRIVATE-KEY-CONTENTS');

        $this->post("/personal/items/{$item->id}/preview")
            ->assertRedirect('/login');
    }

    /**
     * Ownership is checked before the type: a stranger probing a foreign
     * SECRET id must get the same audited 403 as probing a file - a 404 there
     * would be a type oracle, and the probe would leave no trace.
     */
    public function test_probing_someone_elses_secret_is_a_403_and_leaves_a_trace(): void
    {
        $secret = PersonalSecret::create([
            'user_id' => $this->owner->id,
            'type' => SecretItemType::Secret,
            'name' => 'GitHub token',
            'value' => 'ghp_fake_token',
        ]);

        $this->actingAs($this->stranger)
            ->postJson("/personal/items/{$secret->id}/preview")
            ->assertForbidden();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'personal.access-denied',
            'causer_id' => $this->stranger->id,
        ]);
    }

    public function test_the_owner_downloads_their_file_with_no_store_and_an_audit_entry(): void
    {
        $item = $this->storeFile('server.pem', 'PRIVATE-KEY-CONTENTS');

        $response = $this->actingAs($this->owner)
            ->get("/personal/items/{$item->id}/download");

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('PRIVATE-KEY-CONTENTS', $response->streamedContent());
        $this->assertDatabaseHas('activity_log', ['event' => 'personal.revealed']);
    }

    public function test_a_stranger_cannot_download_and_a_guest_is_redirected(): void
    {
        $item = $this->storeFile('server.pem', 'PRIVATE-KEY-CONTENTS');

        $this->actingAs($this->stranger)
            ->get("/personal/items/{$item->id}/download")
            ->assertForbidden();

        $this->post('/logout');
        $this->get("/personal/items/{$item->id}/download")
            ->assertRedirect('/login');
    }

    public function test_reveals_are_rate_limited(): void
    {
        $item = $this->storeFile('.env', 'APP_KEY=fake');

        foreach (range(1, 30) as $i) {
            $this->actingAs($this->owner)
                ->postJson("/personal/items/{$item->id}/preview")
                ->assertOk();
        }

        $this->actingAs($this->owner)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertStatus(429);
    }

    public function test_a_deleted_item_cannot_be_previewed(): void
    {
        $item = $this->storeFile('server.pem', 'PRIVATE-KEY-CONTENTS');
        $item->delete();

        $this->actingAs($this->owner)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertNotFound();
    }

    public function test_previewing_is_audited_as_a_reveal(): void
    {
        $item = $this->storeFile('.env', 'APP_KEY=fake');

        $this->actingAs($this->owner)
            ->postJson("/personal/items/{$item->id}/preview")
            ->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'personal.revealed',
            'causer_id' => $this->owner->id,
            'organization_id' => null,
            'project_id' => null,
        ]);
    }
}
