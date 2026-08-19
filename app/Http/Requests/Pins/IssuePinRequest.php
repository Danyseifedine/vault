<?php

namespace App\Http\Requests\Pins;

use Illuminate\Foundation\Http\FormRequest;

class IssuePinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'pin' => ['required', 'string', 'digits:4'],
            'label' => ['nullable', 'string', 'max:60'],
        ];
    }
}
