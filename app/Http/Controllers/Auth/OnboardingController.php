<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\EnableTwoFactorAuthentication;
use App\Actions\Onboarding\CompleteProfile;
use App\Actions\Onboarding\SetInitialPassword;
use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OnboardingPasswordRequest;
use App\Http\Requests\Auth\OnboardingProfileRequest;
use App\Http\Requests\Auth\OnboardingTwoFactorRequest;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;

class OnboardingController extends Controller
{
    /**
     * Show the stepper, telling the frontend which step is outstanding.
     */
    public function show(): Response|RedirectResponse
    {
        $user = request()->user();
        $step = OnboardingStep::for($user);

        if ($step === OnboardingStep::Done) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/onboarding', [
            'step' => $step->value,
            'email' => $user->email,
            'name' => $user->name,
            'twoFactor' => $step === OnboardingStep::TwoFactor ? $this->provision($user) : null,
        ]);
    }

    public function storePassword(OnboardingPasswordRequest $request, SetInitialPassword $setPassword): RedirectResponse
    {
        $setPassword($request->user(), $request->validated('password'));

        return redirect()->route('onboarding.show');
    }

    /**
     * Confirm the second factor with a code from the app that just scanned the
     * QR. Fortify's action reports into its own error bag, which Inertia never
     * looks at - so the failure is re-raised against the field the form has.
     */
    public function storeTwoFactor(
        OnboardingTwoFactorRequest $request,
        ConfirmTwoFactorAuthentication $confirm,
        AuditRecorder $audit,
    ): RedirectResponse {
        try {
            $confirm($request->user(), $request->validated('code'));
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'code' => 'That code did not match. Check your authenticator app and use the current one.',
            ]);
        }

        $audit->record(
            'account.two-factor-confirmed',
            scope: AuditScope::none(),
            causer: $request->user(),
        );

        return redirect()->route('onboarding.show');
    }

    public function storeProfile(OnboardingProfileRequest $request, CompleteProfile $completeProfile): RedirectResponse
    {
        $completeProfile($request->user(), [
            'name' => $request->validated('name'),
            'job_title' => $request->validated('job_title'),
            'stack' => $request->validated('stack'),
        ]);

        return redirect()->route('dashboard');
    }

    /**
     * Mint the secret on first arrival and hand back what the screen needs to
     * show it. Reloading must not rotate it: the QR someone scanned a moment
     * ago has to keep working, so a secret is only created when absent.
     *
     * @return array{qr: string, secret: string}
     */
    private function provision(User $user): array
    {
        if ($user->two_factor_secret === null) {
            app(EnableTwoFactorAuthentication::class)($user);
            $user->refresh();
        }

        return [
            'qr' => $user->twoFactorQrCodeSvg(),
            // The one moment anyone is shown their own secret: some
            // authenticators cannot scan, and typing it is the fallback.
            'secret' => decrypt($user->two_factor_secret),
        ];
    }
}
