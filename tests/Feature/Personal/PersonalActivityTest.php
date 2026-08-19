<?php

namespace Tests\Feature\Personal;

use App\Actions\PersonalVault\CreatePersonalSecret;
use App\Actions\PersonalVault\RevealPersonalSecret;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The private personal log, on screen.
 *
 * A personal log that only the owner can query; this is
 * the querying. The page shows the owner their own trail - and nothing that
 * happened inside any organization, and never a value.
 */
class PersonalActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->owner = User::factory()->onboarded()->create();
    }

    public function test_the_page_carries_the_owners_personal_trail(): void
    {
        $secret = app(CreatePersonalSecret::class)($this->owner, 'GitHub token', 'ghp_fake_token');
        app(RevealPersonalSecret::class)($secret, $this->owner);

        $this->actingAs($this->owner)
            ->get('/personal')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('personal/index')
                ->has('activity', 2)
                ->where('activity.0.action', 'personal revealed')
                ->where('activity.1.action', 'personal secret created'));
    }

    public function test_the_trail_never_contains_a_value(): void
    {
        $secret = app(CreatePersonalSecret::class)($this->owner, 'GitHub token', 'ghp_fake_token');
        app(RevealPersonalSecret::class)($secret, $this->owner);

        $response = $this->actingAs($this->owner)->get('/personal');

        $this->assertStringNotContainsString('ghp_fake_token', $response->getContent() ?: '');
    }

    public function test_someone_elses_personal_actions_never_appear(): void
    {
        $stranger = User::factory()->onboarded()->create();
        app(CreatePersonalSecret::class)($stranger, 'their token', 'sk_fake_theirs');

        $this->actingAs($this->owner)
            ->get('/personal')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activity', 0));
    }

    public function test_the_trail_is_empty_when_nothing_has_happened(): void
    {
        $this->actingAs($this->owner)
            ->get('/personal')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activity', 0));
    }
}
