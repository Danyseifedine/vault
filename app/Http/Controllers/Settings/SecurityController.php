<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        // No enable/disable props: two-factor authentication is mandatory, so
        // the page states where it stands rather than offering switches that
        // would only bounce the person back into onboarding.
        return Inertia::render('settings/security', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'twoFactorEnabled' => $request->user()->hasConfirmedTwoFactor(),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        // The credential itself never appears - the fact of the change does.
        $audit->record(
            'account.password-changed',
            scope: AuditScope::none(),
            causer: $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
