<?php

namespace App\Http\Requests\Variables;

use Illuminate\Foundation\Http\FormRequest;

class SetVariableValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An empty value is legitimate - plenty of .env keys are deliberately
     * blank - so `present` rather than `required`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'value' => ['present', 'string', 'max:20000'],
        ];
    }
}
