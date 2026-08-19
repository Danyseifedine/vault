<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Nobody reaches the application until they are active, carry confirmed 2FA,
 * and have completed their profile - in that order.
 */
class OnboardingGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fully_onboarded_user_reaches_the_application(): void
    {
        $this->actingAs(User::factory()->onboarded()->create())
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_an_invited_account_cannot_reach_the_application(): void
    {
        $this->actingAs(User::factory()->invited()->create())
            ->get('/dashboard')
            ->assertRedirect(route('onboarding.show'));
    }

    public function test_a_user_without_confirmed_two_factor_is_sent_to_onboarding(): void
    {
        $user = User::factory()->onboarded()->create(['two_factor_confirmed_at' => null]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('onboarding.show'));
    }

    public function test_a_user_with_an_incomplete_profile_is_sent_to_onboarding(): void
    {
        $user = User::factory()->onboarded()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('onboarding.show'));
    }

    public function test_the_stepper_reports_the_step_the_user_is_actually_on(): void
    {
        $invited = User::factory()->invited()->create();

        $this->actingAs($invited)
            ->get(route('onboarding.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/onboarding')->where('step', 'password'));

        $needsTwoFactor = User::factory()->onboarded()->create(['two_factor_confirmed_at' => null]);

        $this->actingAs($needsTwoFactor)
            ->get(route('onboarding.show'))
            ->assertInertia(fn ($page) => $page->where('step', 'two-factor'));

        $needsProfile = User::factory()->onboarded()->create(['profile_completed_at' => null]);

        $this->actingAs($needsProfile)
            ->get(route('onboarding.show'))
            ->assertInertia(fn ($page) => $page->where('step', 'profile'));
    }

    public function test_an_invited_user_sets_a_password_and_becomes_active(): void
    {
        $user = User::factory()->invited()->create();

        $this->actingAs($user)
            ->post(route('onboarding.password'), [
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertRedirect(route('onboarding.show'));

        $user->refresh();

        $this->assertTrue($user->isActive());
        $this->assertNotNull($user->password);
        $this->assertDatabaseHas('activity_log', ['event' => 'onboarding.password-set']);
    }

    public function test_the_profile_step_requires_a_name_and_job_title(): void
    {
        $user = User::factory()->onboarded()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->post(route('onboarding.profile'), ['name' => '', 'job_title' => ''])
            ->assertSessionHasErrors(['name', 'job_title']);

        $this->assertNull($user->fresh()->profile_completed_at);
    }

    public function test_completing_the_profile_opens_the_application(): void
    {
        $user = User::factory()->onboarded()->create(['profile_completed_at' => null]);

        $this->actingAs($user)->post(route('onboarding.profile'), [
            'name' => 'Nadia Rahal',
            'job_title' => 'Backend Developer',
            'stack' => ['Laravel', 'PostgreSQL'],
        ])->assertRedirect(route('dashboard'));

        $user->refresh();

        $this->assertSame('Nadia Rahal', $user->name);
        $this->assertSame('Backend Developer', $user->job_title);
        $this->assertSame(['Laravel', 'PostgreSQL'], $user->stack);
        $this->assertNotNull($user->profile_completed_at);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertDatabaseHas('activity_log', ['event' => 'onboarding.completed']);
    }

    public function test_password_reset_is_refused_for_an_invited_account(): void
    {
        Notification::fake();

        $invited = User::factory()->invited()->create(['email' => 'reserved@lebify.test']);

        // Must not reveal that the address exists, and must not send anything:
        // a reset link here would walk straight past the invitation.
        $this->post('/forgot-password', ['email' => 'reserved@lebify.test']);

        Notification::assertNotSentTo($invited, ResetPassword::class);
        $this->assertDatabaseHas('activity_log', ['event' => 'auth.password-reset-refused']);
    }

    public function test_password_reset_still_works_for_an_active_account(): void
    {
        Notification::fake();

        $user = User::factory()->onboarded()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_guests_are_untouched_by_the_gate(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get(route('onboarding.show'))->assertRedirect('/login');
    }
}
