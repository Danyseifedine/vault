<?php

namespace App\Http\Requests\Variables;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The PIN and password are optional here on purpose: what a reveal costs is
 * decided by RevealGuard from the environment × sensitivity matrix, and the
 * response tells the client what is still missing. Demanding them up front
 * would leak the policy to people who cannot see the value anyway.
 */
class RevealVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pin' => ['nullable', 'string', 'digits:4'],
            'password' => ['nullable', 'string'],
        ];
    }
}
