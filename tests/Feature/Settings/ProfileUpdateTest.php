<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The profile holds one editable thing: your name, which is what the audit log
 * prints next to everything you do.
 *
 * The email address is the account's identity. Invitations, grants and audit
 * rows were all written against it, so it is fixed. The page shows it read-only
 * and the request refuses to move it, which is the part that actually matters.
 */
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_arrives_with_the_current_values(): void
    {
        $user = User::factory()->onboarded()->create(['name' => 'Dany Seifeddine']);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/profile')
                ->where('name', 'Dany Seifeddine')
                ->where('email', $user->email),
            );
    }

    public function test_the_name_can_be_changed(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'New Name'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('New Name', $user->refresh()->name);
    }

    public function test_a_blank_name_is_refused(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => '   '])
            ->assertSessionHasErrors('name');
    }

    public function test_an_email_sent_anyway_is_ignored(): void
    {
        $user = User::factory()->onboarded()->create();
        $was = $user->email;

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'New Name', 'email' => 'someone.else@example.test']);

        $this->assertSame($was, $user->refresh()->email);
    }

    /** Nothing about the address moved, so nothing about its verification should. */
    public function test_verification_survives_a_rename(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'New Name']);

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->patch(route('profile.update'), ['name' => 'x'])->assertRedirect(route('login'));
    }
}
