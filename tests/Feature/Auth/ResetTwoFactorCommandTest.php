<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Removing recovery codes removes the last self-service way back into an account
 * whose authenticator is gone. This command is the replacement, and it lives on
 * the command line on purpose: clearing someone's second factor is a takeover,
 * so it should cost server access rather than a click.
 */
class ResetTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_clears_the_second_factor(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->artisan('vault:reset-two-factor', ['email' => $user->email, '--force' => true])
            ->assertSuccessful();

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_the_account_survives_the_reset(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->artisan('vault:reset-two-factor', ['email' => $user->email, '--force' => true]);

        $user->refresh();

        $this->assertTrue($user->isActive());
        $this->assertNotNull($user->password);
        $this->assertNotNull($user->profile_completed_at);
    }

    /** The onboarding gate is what actually forces them to set it up again. */
    public function test_the_person_is_pushed_back_through_two_factor_setup(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->artisan('vault:reset-two-factor', ['email' => $user->email, '--force' => true]);

        $this->actingAs($user->refresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.show'));
    }

    public function test_the_reset_is_audited(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->artisan('vault:reset-two-factor', ['email' => $user->email, '--force' => true]);

        $this->assertTrue(
            AuditLog::query()->where('event', 'two-factor.reset')->exists(),
            'Clearing a second factor is exactly the kind of thing the log exists for.',
        );
    }

    public function test_an_unknown_email_changes_nothing(): void
    {
        $this->artisan('vault:reset-two-factor', ['email' => 'nobody@example.test', '--force' => true])
            ->assertFailed();

        $this->assertSame(0, AuditLog::query()->where('event', 'two-factor.reset')->count());
    }

    public function test_an_account_without_a_second_factor_is_left_alone(): void
    {
        $user = User::factory()->create();

        $this->artisan('vault:reset-two-factor', ['email' => $user->email, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, AuditLog::query()->where('event', 'two-factor.reset')->count());
    }

    public function test_it_asks_before_doing_anything_without_force(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->artisan('vault:reset-two-factor', ['email' => $user->email])
            ->expectsConfirmation("Clear the second factor for {$user->email}?", 'no')
            ->assertFailed();

        $this->assertNotNull($user->refresh()->two_factor_confirmed_at);
    }
}
