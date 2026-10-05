<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Auth/PasswordResetController::sendResetLink(). */
class SendResetLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
