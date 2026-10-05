<?php

namespace App\Http\Requests\ManageBooking;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for ManageBookingController::search(). */
class FindBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'booking_number' => ['required', 'string', 'regex:/^CFP-\d{4}-[A-Za-z0-9]{4,10}$/'],
            'pin' => ['required', 'digits:4'],
        ];
    }

    public function messages(): array
    {
        return [
            'booking_number.regex' => 'Please enter a valid booking reference number (e.g. CFP-2026-XXXXX).',
            'pin.digits' => 'The PIN must be exactly 4 digits.',
        ];
    }
}
