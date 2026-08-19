<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Mail\NewLoginMail;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Every successful sign-in emails the account, so a login the owner did not
 * make is noticed at once. The mail is queued from the request, carrying the
 * where/what/when the listener read while the request still existed.
 */
class LoginAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_in_emails_the_account_with_the_sign_in_details(): void
    {
        Mail::fake();

        // An active account that has not confirmed two-factor signs in without
        // the challenge, which still fires the Login event - the simplest way
        // to drive a real login end to end in a test.
        $user = User::factory()->create([
            'status' => UserStatus::Active,
            'two_factor_confirmed_at' => null,
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64) Firefox/121.0'])
            ->post(route('login'), ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);

        Mail::assertQueued(NewLoginMail::class, function (NewLoginMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->device === 'Firefox on Windows'
                && $mail->ip !== '';
        });
    }

    public function test_a_failing_alert_never_breaks_the_sign_in(): void
    {
        // The mailer is down; the login it fires from must still complete. The
        // test passes only if the event does NOT throw - the listener has to
        // swallow the failure rather than let it bubble out of a login that has
        // already succeeded.
        $this->expectNotToPerformAssertions();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('mail server down'));

        $user = User::factory()->onboarded()->create();

        event(new Login('web', $user, false));
    }

    public function test_no_alert_is_sent_for_a_failed_password(): void
    {
        Mail::fake();

        $user = User::factory()->onboarded()->create();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);

        Mail::assertNothingQueued();
    }
}
