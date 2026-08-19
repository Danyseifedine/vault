<?php

namespace App\Http\Requests\Members;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The full replacement set for one member's grants. An optional project_id
 * narrows the rewrite to that project's rows - the project members screen
 * edits one project without knowing about the rest.
 */
class MemberGrantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'grants' => ['present', 'array', 'max:500'],
            'grants.*.permission' => ['required', 'string', Rule::enum(Permission::class)],
            'grants.*.project_id' => ['sometimes', 'nullable', 'integer'],
            'grants.*.environment_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function grants(): array
    {
        return $this->array('grants');
    }
}
