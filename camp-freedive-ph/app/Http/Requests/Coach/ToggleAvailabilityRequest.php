<?php

namespace App\Http\Requests\Coach;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Coach/AvailabilityController::toggle(). */
class ToggleAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date',
        ];
    }
}
