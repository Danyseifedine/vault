<?php

namespace App\Http\Requests\Auth;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class OnboardingPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        // Only a seat that has not been claimed yet may set a first password.
        return $this->user()?->isInvited() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => $this->passwordRules(),
        ];
    }
}
