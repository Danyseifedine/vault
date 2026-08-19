<?php

namespace App\Http\Requests\Auth;

use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * Fortify's challenge accepts either an authenticator code or a recovery code.
 * We take the second door off its hinges: a recovery code is a long-lived
 * password that lives in someone's notes app, and it would let anyone holding
 * it past the exact control two-factor authentication exists to impose.
 *
 * Bound over Fortify's request in FortifyServiceProvider, so the refusal
 * happens before the controller ever looks at the payload.
 */
class TwoFactorChallengeRequest extends TwoFactorLoginRequest
{
    /**
     * `recovery_code` is simply not a field here. The GET renders the screen and
     * carries nothing; only the POST has to bring an authenticator code.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return ['code' => $this->isMethod('post') ? 'required|string' : 'nullable|string'];
    }

    /**
     * Always null - there is no recovery code that can be valid here, whatever
     * the request carries.
     */
    public function validRecoveryCode(): null
    {
        return null;
    }
}
