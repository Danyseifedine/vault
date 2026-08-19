<?php

namespace App\Http\Requests\Variables;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An import arrives either pasted or uploaded - exactly one of the two.
 */
class ImportEnvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'contents' => ['nullable', 'string', 'max:200000'],
            'file' => ['nullable', 'file', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('contents') === $this->hasFile('file')) {
                $validator->errors()->add('contents', 'Paste an .env or upload one - not both.');
            }
        });
    }

    public function contents(): string
    {
        if ($this->hasFile('file')) {
            return (string) $this->file('file')?->get();
        }

        return $this->string('contents')->value();
    }
}
