<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validation for ProfileController::updatePassword(). Separate from ChangePasswordRequest so its
 * errors go to the "changePassword" bag shown on the profile page, not the first-login screen's.
 */
class ProfilePasswordChangeRequest extends FormRequest
{
    protected $errorBag = 'changePassword';

    public function authorize(): bool
    {
        return true; // access is checked by route middleware
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed', 'different:current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.different' => 'Your new password must be different from your current password.',
        ];
    }
}
