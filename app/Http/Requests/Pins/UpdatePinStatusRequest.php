<?php

namespace App\Http\Requests\Pins;

use App\Enums\PinStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePinStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(PinStatus::class)],
        ];
    }

    public function status(): PinStatus
    {
        return PinStatus::from($this->string('status')->value());
    }
}
