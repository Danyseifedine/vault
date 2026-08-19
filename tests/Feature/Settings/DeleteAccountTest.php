<?php

namespace Tests\Feature\Settings;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ManagesAccess;
use Tests\TestCase;

/**
 * Deleting your own account. The interesting case is the one that would
 * otherwise be a 500: organizations point at the person who created them with
 * a restricting foreign key, so a creator cannot simply evaporate.
 */
class DeleteAccountTest extends TestCase
{
    use ManagesAccess, RefreshDatabase;

    public function test_an_account_that_owns_nothing_can_be_deleted(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_the_wrong_password_is_refused(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'not-the-password'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /**
     * Without this the request reaches the database and dies on a foreign key,
     * which reads to whoever pressed the button as "the application is broken"
     * rather than "there is something to do first".
     */
    public function test_a_creator_is_told_to_deal_with_their_organizations_first(): void
    {
        $creator = User::factory()->onboarded()->create();
        Organization::factory()->create(['created_by' => $creator->id]);

        $this->actingAs($creator)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseHas('users', ['id' => $creator->id]);
        $this->assertAuthenticated();
    }

    public function test_merely_belonging_to_an_organization_does_not_block_it(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->joinMember($organization);

        $this->actingAs($member)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }
}
