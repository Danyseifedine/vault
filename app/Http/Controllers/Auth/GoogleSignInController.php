<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Signing in with Google.
 *
 * Google is only ever allowed to *identify* someone who already has an account
 * here. It cannot create one: this product has no public registration, and an
 * OAuth button that provisions accounts would quietly become exactly that.
 *
 * It is also not a substitute for our own second factor. Someone who has
 * confirmed 2FA is handed to the normal challenge rather than signed straight
 * in - Google verifying their identity to Google says nothing about ours.
 */
class GoogleSignInController extends Controller
{
    public function __construct(private AuditRecorder $audit) {}

    public function redirect(): SymfonyRedirect|RedirectResponse
    {
        abort_unless($this->configured(), 404);

        // The stateful flow on purpose: Socialite puts a `state` value in the
        // session and checks it on the way back, which is what stops a forged
        // callback signing someone in. We have sessions, so there is no reason
        // to reach for stateless() and give that up.
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->configured(), 404);

        try {
            $account = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            // Covers a forged or replayed callback as well as a genuine
            // network failure - both end at the login page, neither leaks why.
            $this->audit->failure('auth.google-failed', properties: ['reason' => $exception->getMessage()]);

            return $this->refuse('We could not complete that Google sign-in. Please try again.');
        }

        $email = Str::lower(trim((string) $account->getEmail()));

        if ($email === '') {
            return $this->refuse('That Google account has no email address we can match.');
        }

        $user = User::query()->where('email', $email)->first();

        // No account, no entry - and the message deliberately does not confirm
        // whether the address exists here.
        if ($user === null) {
            $this->audit->failure('auth.google-rejected', properties: ['email' => $email]);

            return $this->refuse('That Google account is not associated with a vault account. Ask to be invited first.');
        }

        // Signing in through Google proves control of the inbox, which is
        // exactly what email verification is asking about.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $this->audit->record('auth.google-login', properties: ['email' => $email], causer: $user);

        if ($user->hasConfirmedTwoFactor()) {
            // The same handover Fortify performs after a correct password.
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        // The onboarding gate decides where they actually belong next.
        return redirect()->route('onboarding.show');
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    private function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }
}
