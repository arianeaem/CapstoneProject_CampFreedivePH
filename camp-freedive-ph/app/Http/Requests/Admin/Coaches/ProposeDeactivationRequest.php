<?php

namespace App\Http\Requests\Admin\Coaches;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/DeactivationController::propose(). */
class ProposeDeactivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
