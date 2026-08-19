<?php

namespace App\Http\Requests\Variables;

use App\Enums\ChangeSafety;
use App\Enums\Sensitivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Env keys are conventionally SCREAMING_SNAKE_CASE; anything else
            // will not survive a round trip through a .env file.
            'key' => ['required', 'string', 'max:120', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sensitivity' => ['sometimes', Rule::enum(Sensitivity::class)],
            'change_safety' => ['sometimes', Rule::enum(ChangeSafety::class)],
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
        return Sensitivity::from($this->string('sensitivity', Sensitivity::Sensitive->value)->value());
    }

    public function changeSafety(): ChangeSafety
    {
        return ChangeSafety::from($this->string('change_safety', ChangeSafety::Safe->value)->value());
    }
}
