<?php

namespace App\Http\Requests\SharedVault;

use Illuminate\Foundation\Http\FormRequest;

/** Reading a shared item always costs a PIN - there is no policy that waives it. */
class RevealSharedSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'digits:4'],
        ];
    }
}
