<?php

namespace App\Http\Requests\Auth;

use App\Enums\OnboardingStep;
use Illuminate\Foundation\Http\FormRequest;

class OnboardingTwoFactorRequest extends FormRequest
{
    /**
     * Only while two-factor setup is the outstanding step. That refuses both
     * ends of the mistake: an unclaimed account skipping ahead of its password,
     * and a finished account re-provisioning its second factor from a screen
     * that never asks for the current one.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && OnboardingStep::for($user) === OnboardingStep::TwoFactor;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
