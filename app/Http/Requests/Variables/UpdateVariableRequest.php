<?php

namespace App\Http\Requests\Variables;

use App\Enums\ChangeSafety;
use App\Enums\Sensitivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:120', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sensitivity' => ['required', Rule::enum(Sensitivity::class)],
            'change_safety' => ['required', Rule::enum(ChangeSafety::class)],
            'group_id' => ['nullable', 'integer'],
            // A name instead of an id files the variable under a brand new
            // group - see VariableController::groupIdFor.
            'group_name' => ['nullable', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'key.regex' => 'Use capitals, numbers and underscores only - for example DATABASE_URL.',
        ];
    }

    public function sensitivity(): Sensitivity
    {
        return Sensitivity::from($this->string('sensitivity')->value());
    }

    public function changeSafety(): ChangeSafety
    {
        return ChangeSafety::from($this->string('change_safety')->value());
    }
}
