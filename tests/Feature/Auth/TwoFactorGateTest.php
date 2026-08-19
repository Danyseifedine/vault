<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The promise: nothing in the application answers to anyone who has not fully
 * signed in, and "fully" means the two-factor code, not just the password.
 *
 * There are two ways to be short of that, and both must reach nothing:
 *  - a guest, and the half-authenticated state between password and code (the
 *    person has given a password but not the code, so Fortify has NOT logged
 *    them in) - the `auth` gate turns both away;
 *  - a signed-in account that has not yet confirmed two-factor (mid-onboarding)
 *    - the `onboarded` gate holds it at the stepper.
 *
 * This walks a broad set of the routes that read or return real data - the
 * reveal endpoints among them - and proves each one is closed in every state.
 */
class TwoFactorGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sensitive routes, addressed with throwaway ids: the `auth` gate runs
     * before route-model binding, so a closed door never even looks the id up.
     *
     * @return array<int, array{string, string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            ['get', '/dashboard'],
            ['get', '/components'],
            ['post', '/orgs'],
            ['get', '/orgs/1'],
            ['get', '/orgs/1?screen=shared'],
            ['get', '/orgs/1/projects/1'],
            // The endpoints that return an actual secret value.
            ['post', '/orgs/1/projects/1/variables/1/environments/1/reveal'],
            ['get', '/orgs/1/projects/1/environments/1/export'],
            ['post', '/orgs/1/shared/1/reveal'],
            ['post', '/orgs/1/shared/1/download'],
            // The personal vault.
            ['get', '/personal'],
            ['post', '/personal/items/1/reveal'],
            ['get', '/personal/items/1/download'],
            // Account settings.
            ['get', '/settings/profile'],
            ['get', '/settings/security'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_a_guest_is_turned_away_from_every_protected_route(string $method, string $uri): void
    {
        $this->{$method}($uri)->assertRedirect(route('login'));
    }

    /**
     * The exact scenario: a valid password was given, the two-factor code was
     * NOT. Fortify leaves a `login.id` in the session but does not authenticate,
     * so every protected route must still turn the request away.
     */
    #[DataProvider('protectedRoutes')]
    public function test_a_pending_two_factor_session_reaches_nothing(string $method, string $uri): void
    {
        $user = User::factory()->onboarded()->create();

        // Password accepted, challenge NOT completed.
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

        // Half-authenticated: the pending id exists, but there is no auth.
        $this->assertTrue(session()->has('login.id'));
        $this->assertGuest();

        $this->{$method}($uri)->assertRedirect(route('login'));
    }

    /**
     * A signed-in account that has not confirmed two-factor (mid-onboarding) is
     * held at the stepper, never reaching the vault. Only no-binding routes are
     * checked here so the `onboarded` redirect is unambiguous - the guest and
     * pending cases above already cover the bound routes.
     */
    public function test_an_account_without_confirmed_two_factor_reaches_no_vault_data(): void
    {
        $user = User::factory()->onboarded()->create(['two_factor_confirmed_at' => null]);

        foreach ([['get', '/dashboard'], ['get', '/personal'], ['post', '/orgs'], ['get', '/components']] as [$method, $uri]) {
            $this->actingAs($user)->{$method}($uri)->assertRedirect(route('onboarding.show'));
        }
    }

    /**
     * The complement, so the gate is proven to OPEN too: a fully signed-in
     * account (password, confirmed two-factor, completed profile) gets in.
     */
    public function test_a_fully_authenticated_account_gets_in(): void
    {
        $this->actingAs(User::factory()->onboarded()->create())
            ->get('/dashboard')
            ->assertOk();
    }
}
