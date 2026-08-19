<?php

namespace App\Http\Requests\Projects;

use App\Enums\RevealRequirement;
use App\Enums\Sensitivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRevealPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sensitivity' => ['required', Rule::enum(Sensitivity::class)],
            'requirement' => ['required', Rule::enum(RevealRequirement::class)],
        ];
    }

    public function sensitivity(): Sensitivity
    {
        return Sensitivity::from($this->string('sensitivity')->value());
    }

    public function requirement(): RevealRequirement
    {
        return RevealRequirement::from($this->string('requirement')->value());
    }
}
