<?php

namespace App\Http\Requests\ManageBooking;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for ManageBookingController::cancel(). */
class CancelBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'digits:4'],
            'confirm_cancel_ack' => 'required|accepted',
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'pin.digits' => 'The PIN must be exactly 4 digits.',
        ];
    }
}
