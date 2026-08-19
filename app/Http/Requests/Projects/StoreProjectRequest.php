<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    /** Authorization lives in the controller's policy call, not here. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'environments' => ['sometimes', 'array', 'min:1', 'max:10'],
            'environments.*' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * The usual three unless the creator said otherwise.
     *
     * @return array<int, string>
     */
    public function environments(): array
    {
        if (! $this->has('environments')) {
            return ['dev', 'staging', 'prod'];
        }

        return array_map(strval(...), $this->array('environments'));
    }
}
