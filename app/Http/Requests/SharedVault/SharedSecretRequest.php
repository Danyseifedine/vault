<?php

namespace App\Http\Requests\SharedVault;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One shared item on the way in - a typed secret, an uploaded file, or an edit
 * of either. Which fields are required is decided by what was sent: a file
 * upload carries no `value`, and an edit that only renames carries neither.
 */
class SharedSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [$this->hasFile('file') ? 'nullable' : 'required', 'string', 'max:120'],
            // Required only when creating a typed secret; an edit leaves the
            // stored value alone unless a new one is typed.
            'value' => [
                $this->isMethod('POST') && ! $this->hasFile('file') ? 'required' : 'nullable',
                'string',
                'max:20000',
            ],
            'file' => ['sometimes', 'file', 'max:10240'],
            'shared_group_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function groupId(): ?int
    {
        return $this->input('shared_group_id') === null
            ? null
            : $this->integer('shared_group_id');
    }
}
