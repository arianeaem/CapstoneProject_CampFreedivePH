<?php

namespace App\Http\Requests\Admin\Bookings;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/BookingController::update(). */
class UpdateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware
    }

    /** Clean up the form input before validation. */
    protected function prepareForValidation(): void
    {
        // Remove spaces and dashes from the phone number
        if ($this->has('contact_phone')) {
            $cleanedPhone = preg_replace('/[\s\-]/', '', (string)$this->input('contact_phone'));
            $this->merge(['contact_phone' => $cleanedPhone]);
        }
    }

    public function rules(): array
    {
        return [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'pickup_option' => 'nullable|in:none,own,carpool',
            'pickup_location' => 'nullable|string|max:255',
            'boat_dive' => 'nullable|boolean',
            'contact_name' => 'required|string|min:2|max:100|regex:/^[\pL\s\.\'\-]+$/u',
            'contact_email' => 'required|email:rfc,filter|max:255',
            'contact_phone' => ['required', 'string', 'regex:/^(\+?63|0)9\d{9}$/'],
            'contact_facebook' => 'nullable|string|max:255',
            'participants' => 'required|array|min:1|max:45',
            'participants.*.id' => 'nullable|integer',
            'participants.*.name' => 'required|string|min:2|max:100|regex:/^[\pL\s\.\'\-]+$/u',
            'participants.*.birthdate' => 'nullable|date|before_or_equal:today',
            'participants.*.gender' => 'nullable|in:male,female,non_binary,prefer_not_to_say',
            'participants.*.age' => 'required_without:participants.*.birthdate|nullable|integer|min:8|max:85',
            'participants.*.health_condition' => 'nullable|string|max:1000',
            'participants.*.swimmer_status' => 'nullable|string|max:50',
            'edit_reason' => 'required|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'Please enter a valid Philippine mobile number (e.g. 09171234567 or +639171234567).',
            'contact_email.email' => 'Please provide a valid email address.',
            'participants.*.age.min' => 'Participant age must be at least 8 years old.',
            'participants.*.age.max' => 'Participant age cannot exceed 85 years old.',
            'participants.*.name.regex' => 'Participant names must contain letters only.',
            'contact_name.regex' => 'Contact name must contain letters only.',
        ];
    }
}
