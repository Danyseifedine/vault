<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    }

    public function test_two_factor_challenge_redirects_to_login_when_not_authenticated(): void
    {
        $response = $this->get(route('two-factor.login'));

        $response->assertRedirect(route('login'));
    }

    public function test_two_factor_challenge_can_be_rendered(): void
    {
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/two-factor-challenge'),
            );
    }

    /**
     * Between the password and the code there is a half-authenticated state you
     * could otherwise only escape by clearing cookies. Cancelling drops it.
     */
    public function test_the_challenge_can_be_abandoned(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

        $this->assertTrue(session()->has('login.id'));

        $this->post(route('two-factor.cancel'))->assertRedirect(route('login'));

        $this->assertFalse(session()->has('login.id'));
        $this->assertGuest();
    }

    public function test_abandoning_a_challenge_that_was_never_started_is_harmless(): void
    {
        $this->post(route('two-factor.cancel'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_challenge_screen_is_unreachable_once_abandoned(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.cancel'));

        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    }
}
