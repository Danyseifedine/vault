<?php

namespace App\Http\Requests\Variables;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Syncing tags is a complete list of ids that replaces whatever was there.
 * Which ids are legitimate is decided by the action, which knows the scope
 * rules.
 */
class IdListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['present', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ];
    }

    /** @return array<int, int> */
    public function ids(): array
    {
        return array_map(intval(...), $this->array('ids'));
    }
}
