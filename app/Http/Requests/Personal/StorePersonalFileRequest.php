<?php

namespace App\Http\Requests\Personal;

use Illuminate\Foundation\Http\FormRequest;

class StorePersonalFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240'],
            'name' => ['nullable', 'string', 'max:120'],
            'personal_group_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
