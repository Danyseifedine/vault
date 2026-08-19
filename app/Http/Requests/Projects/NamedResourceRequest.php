<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Groups and environments are both "a name and a position" - one request class
 * rather than two identical ones.
 */
class NamedResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'position' => ['sometimes', 'integer', 'between:0,999'],
        ];
    }
}
