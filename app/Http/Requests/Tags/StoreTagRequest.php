<?php

namespace App\Http\Requests\Tags;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A tag's scope is chosen at creation and never changes afterwards: widening
 * one later would retroactively legitimise attachments that were refused.
 */
class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'environment_id' => ['nullable', 'integer', 'exists:environments,id'],
        ];
    }

    /** Both scope hints at once would be ambiguous rather than more precise. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('project_id') && $this->filled('environment_id')) {
                $validator->errors()->add('scope', 'Choose a project or an environment, not both.');
            }
        });
    }
}
