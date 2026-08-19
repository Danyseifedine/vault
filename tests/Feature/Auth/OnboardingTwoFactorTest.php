<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Two-factor authentication is mandatory and there are no recovery codes, so
 * the setup step is the one moment a person ever sees their secret. It has to
 * hand them a QR code they can scan - and it has to hand them the same one
 * every time they reload, or the code they just scanned stops working.
 */
class OnboardingTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_setup_step_hands_over_a_qr_code_and_a_typeable_key(): void
    {
        $this->actingAs($this->needsTwoFactor())
            ->get(route('onboarding.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/onboarding')
                ->where('step', 'two-factor')
                ->has('twoFactor.qr')
                ->has('twoFactor.secret'),
            );
    }

    public function test_reloading_does_not_rotate_the_secret(): void
    {
        $user = $this->needsTwoFactor();

        $this->actingAs($user)->get(route('onboarding.show'));
        $first = $user->refresh()->two_factor_secret;

        $this->actingAs($user)->get(route('onboarding.show'));

        $this->assertSame($first, $user->refresh()->two_factor_secret);
    }

    public function test_the_right_code_confirms_two_factor(): void
    {
        $user = $this->provisioned();

        $this->actingAs($user)
            ->post(route('onboarding.two-factor'), ['code' => $this->currentCode($user)])
            ->assertRedirect(route('onboarding.show'));

        $this->assertNotNull($user->refresh()->two_factor_confirmed_at);
    }

    public function test_a_wrong_code_leaves_two_factor_unconfirmed(): void
    {
        $user = $this->provisioned();

        $this->actingAs($user)
            ->post(route('onboarding.two-factor'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->refresh()->two_factor_confirmed_at);
    }

    /** The error has to reach the default bag or the page renders it nowhere. */
    public function test_a_wrong_code_reports_against_the_field(): void
    {
        $user = $this->provisioned();

        $this->actingAs($user)
            ->post(route('onboarding.two-factor'), ['code' => '000000'])
            ->assertInvalid(['code']);
    }

    public function test_an_unclaimed_account_owes_a_password_before_a_second_factor(): void
    {
        $invited = User::factory()->invited()->create();

        $this->actingAs($invited)
            ->get(route('onboarding.show'))
            ->assertInertia(fn (Assert $page) => $page->where('step', 'password')->where('twoFactor', null));

        $this->actingAs($invited)
            ->post(route('onboarding.two-factor'), ['code' => '123456'])
            ->assertForbidden();

        $this->assertNull($invited->refresh()->two_factor_secret);
    }

    public function test_someone_who_already_has_two_factor_cannot_reprovision_through_onboarding(): void
    {
        $user = User::factory()->onboarded()->create();
        $secret = $user->two_factor_secret;

        $this->actingAs($user)->get(route('onboarding.show'))->assertRedirect(route('dashboard'));
        $this->actingAs($user)->post(route('onboarding.two-factor'), ['code' => '123456'])->assertForbidden();

        $this->assertSame($secret, $user->refresh()->two_factor_secret);
    }

    public function test_setup_never_mints_recovery_codes(): void
    {
        $user = $this->needsTwoFactor();

        $this->actingAs($user)->get(route('onboarding.show'));

        $this->assertNull($user->refresh()->two_factor_recovery_codes);
    }

    /** An active account with a password and a profile, but no second factor. */
    private function needsTwoFactor(): User
    {
        return User::factory()->onboarded()->create([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    /** The same, after the setup screen has handed them a secret. */
    private function provisioned(): User
    {
        $user = $this->needsTwoFactor();

        $this->actingAs($user)->get(route('onboarding.show'));

        return $user->refresh();
    }

    private function currentCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
    }
}
