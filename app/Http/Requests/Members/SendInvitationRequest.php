<?php

namespace App\Http\Requests\Members;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An invitation carries the whole grant list - exactly what this person may
 * do, permission by permission, scope by scope. Everything is written the
 * moment the invitation is sent, so acceptance has nothing to recalculate.
 *
 * Shape checks only: whether each grant's ids belong to this organization and
 * whether the permission fits the scope is GrantWriter::normalize's job.
 */
class SendInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
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
