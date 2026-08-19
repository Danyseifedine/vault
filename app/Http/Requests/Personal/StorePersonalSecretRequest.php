<?php

namespace App\Http\Requests\Personal;

use Illuminate\Foundation\Http\FormRequest;

class StorePersonalSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'value' => ['required', 'string', 'max:20000'],
            'personal_group_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
