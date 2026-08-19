<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * There are no recovery codes in The Vault. A printed code that walks past
 * two-factor authentication is a second, weaker password living in a text file
 * - so the authenticator app is the only way through, and losing it means an
 * owner resets your second factor.
 *
 * These tests assert the door is closed on the server, not merely hidden.
 */
class RecoveryCodeLoginTest extends TestCase
{
    use RefreshDatabase;

    /** A real base32 secret, so the TOTP provider can actually verify against it. */
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    }

    public function test_a_recovery_code_is_refused_at_the_challenge(): void
    {
        $user = $this->challenged(recoveryCodes: ['valid-recovery-code']);

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => 'valid-recovery-code',
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_recovery_code_smuggled_alongside_a_wrong_otp_is_still_refused(): void
    {
        $this->challenged(recoveryCodes: ['valid-recovery-code']);

        $this->post(route('two-factor.login.store'), [
            'code' => '000000',
            'recovery_code' => 'valid-recovery-code',
        ]);

        $this->assertGuest();
    }

    public function test_the_authenticator_code_still_signs_you_in(): void
    {
        $user = $this->challenged();

        $this->post(route('two-factor.login.store'), [
            'code' => app(Google2FA::class)->getCurrentOtp(self::SECRET),
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_recovery_code_endpoints_are_gone(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get(route('two-factor.recovery-codes'))->assertNotFound();
        $this->post(route('two-factor.regenerate-recovery-codes'))->assertNotFound();
    }

    public function test_turning_on_two_factor_stores_no_recovery_codes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.enable'));

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret, 'The secret itself must still be created.');
        $this->assertNull($user->two_factor_recovery_codes);
    }

    /** Drive a real login up to - but not through - the challenge. */
    private function challenged(array $recoveryCodes = []): User
    {
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

        $user = User::factory()->create([
            'two_factor_secret' => encrypt(self::SECRET),
            'two_factor_recovery_codes' => $recoveryCodes === []
                ? null
                : encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        return $user;
    }
}
