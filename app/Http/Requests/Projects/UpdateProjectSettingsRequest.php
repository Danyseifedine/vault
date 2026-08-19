<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The bounds match UpdateProjectSettings::LIMITS. Both layers validate on
     * purpose - the client is UX, the server is truth.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'audit_views' => ['sometimes', 'boolean'],
            'pin_max_attempts' => ['sometimes', 'integer', 'between:1,20'],
            'pin_lockout_minutes' => ['sometimes', 'integer', 'between:1,1440'],
        ];
    }
}
