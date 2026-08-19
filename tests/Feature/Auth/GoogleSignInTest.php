<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Signing in with Google.
 *
 * The rule that makes this safe in an invite-only product: Google can only ever
 * *identify* someone who already has an account here. It can never create one,
 * and it is not a way past two-factor authentication.
 */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google', [
            'client_id' => 'fake-client-id',
            'client_secret' => 'fake-client-secret',
            'redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    private function googleReturns(string $email, ?string $name = 'Someone'): void
    {
        $account = Mockery::mock(SocialiteUser::class);
        $account->shouldReceive('getEmail')->andReturn($email);
        $account->shouldReceive('getName')->andReturn($name);

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($account);

        $factory = Mockery::mock(SocialiteFactory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($provider);

        $this->app->instance(SocialiteFactory::class, $factory);
    }

    private function events(): array
    {
        return DB::table('activity_log')->pluck('event')->all();
    }

    public function test_the_redirect_sends_the_visitor_to_google(): void
    {
        $response = $this->get(route('google.redirect'));

        $response->assertRedirectContains('accounts.google.com');
    }

    public function test_the_routes_are_unavailable_when_google_is_not_configured(): void
    {
        config()->set('services.google.client_id', null);

        $this->get(route('google.redirect'))->assertNotFound();
    }

    /**
     * The whole point. There is no public registration in this product, and
     * Google must not become one.
     */
    public function test_an_unknown_google_account_never_creates_a_user(): void
    {
        $this->googleReturns('stranger@example.test');

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
        $this->assertContains('auth.google-rejected', $this->events());
    }

    public function test_an_active_user_without_two_factor_is_signed_in_and_sent_to_onboarding(): void
    {
        $user = User::factory()->create([
            'email' => 'dev@lebify.test',
            'status' => UserStatus::Active,
            'two_factor_confirmed_at' => null,
        ]);

        $this->googleReturns('dev@lebify.test');

        $this->get(route('google.callback'))->assertRedirect(route('onboarding.show'));

        $this->assertAuthenticatedAs($user);
        $this->assertContains('auth.google-login', $this->events());
    }

    /**
     * Google is an identity check, not a second factor of ours. Someone who has
     * set up 2FA still has to pass it.
     */
    public function test_a_user_with_two_factor_must_still_pass_the_challenge(): void
    {
        $user = User::factory()->withTwoFactor()->create([
            'email' => 'dev@lebify.test',
            'status' => UserStatus::Active,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->googleReturns('dev@lebify.test');

        $this->get(route('google.callback'))->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
    }

    public function test_signing_in_with_google_verifies_the_email(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'dev@lebify.test',
            'status' => UserStatus::Active,
            'two_factor_confirmed_at' => null,
        ]);

        $this->assertNull($user->email_verified_at);

        $this->googleReturns('dev@lebify.test');
        $this->get(route('google.callback'));

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    /**
     * An invited account has no password yet and password reset is refused for
     * it, so Google is a legitimate way in - it just lands on the stepper.
     */
    public function test_an_invited_account_can_come_in_through_google(): void
    {
        $user = User::factory()->create([
            'email' => 'newcomer@lebify.test',
            'status' => UserStatus::Invited,
            'password' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $this->googleReturns('newcomer@lebify.test');

        $this->get(route('google.callback'))->assertRedirect(route('onboarding.show'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_match_is_on_email_and_is_case_insensitive(): void
    {
        $user = User::factory()->create([
            'email' => 'dev@lebify.test',
            'status' => UserStatus::Active,
            'two_factor_confirmed_at' => null,
        ]);

        $this->googleReturns('  DEV@Lebify.TEST  ');

        $this->get(route('google.callback'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_google_account_with_no_email_is_refused(): void
    {
        $this->googleReturns('');

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_failed_exchange_is_reported_rather_than_thrown(): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('bad state'));

        $factory = Mockery::mock(SocialiteFactory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($provider);
        $this->app->instance(SocialiteFactory::class, $factory);

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertContains('auth.google-failed', $this->events());
    }

    public function test_the_rejection_records_the_address_that_tried(): void
    {
        $this->googleReturns('stranger@example.test');

        $this->get(route('google.callback'));

        $entry = DB::table('activity_log')->where('event', 'auth.google-rejected')->first();

        $this->assertStringContainsString('stranger@example.test', (string) $entry->properties);
    }

    public function test_an_already_signed_in_user_is_left_alone(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);

        $this->actingAs($user)->get(route('google.redirect'))->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
