<?php

namespace App\Http\Requests\Personal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One personal item on the way in: its labels, and optionally a new value.
 *
 * `value` is nullable on purpose. A blank one means "keep the stored one", so
 * a rename can never overwrite the secret it was meant to describe - the same
 * rule SharedSecretRequest applies to the team vault. The bound matches
 * StorePersonalSecretRequest, because a rotated secret is no smaller than a
 * new one.
 */
class UpdatePersonalItemRequest extends FormRequest
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
            'value' => ['nullable', 'string', 'max:20000'],
            'personal_group_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
