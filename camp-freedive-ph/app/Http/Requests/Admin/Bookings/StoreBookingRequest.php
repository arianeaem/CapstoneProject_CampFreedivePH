<?php

namespace App\Http\Requests\Admin\Bookings;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/BookingController::store(). */
class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware
    }

    /** Clean up the form input before validation. */
    protected function prepareForValidation(): void
    {
        // Combine the contact's first, middle, last name and suffix
        if ($this->filled('first_name') || $this->filled('last_name')) {
            $cfn = trim($this->input('first_name') ?? '');
            $cmn = $this->boolean('no_middle_name') ? '' : trim($this->input('middle_name') ?? '');
            $cln = trim($this->input('last_name') ?? '');
            $csuf = trim($this->input('suffix') ?? '');
            if ($csuf === 'None' || $csuf === 'none') {
                $csuf = '';
            }
            $contactName = implode(' ', array_filter([$cfn, $cmn, $cln, $csuf]));
            if ($contactName !== '') {
                $this->merge(['contact_name' => $contactName]);
            }
        }

        // Combine each participant's first, middle, last name and suffix
        if ($this->has('participants') && is_array($this->input('participants'))) {
            $participants = $this->input('participants');
            foreach ($participants as $i => $p) {
                if (isset($p['first_name']) || isset($p['last_name'])) {
                    $pfn = trim($p['first_name'] ?? '');
                    $pmn = !empty($p['no_middle_name']) ? '' : trim($p['middle_name'] ?? '');
                    $pln = trim($p['last_name'] ?? '');
                    $psuf = trim($p['suffix'] ?? '');
                    if ($psuf === 'None' || $psuf === 'none') {
                        $psuf = '';
                    }
                    $pName = implode(' ', array_filter([$pfn, $pmn, $pln, $psuf]));
                    if ($pName !== '') {
                        $participants[$i]['name'] = $pName;
                    }
                }
            }
            $this->merge(['participants' => $participants]);
        }

        // Remove spaces and dashes from the phone number
        if ($this->has('contact_phone')) {
            $cleanedPhone = preg_replace('/[\s\-]/', '', (string)$this->input('contact_phone'));
            $this->merge(['contact_phone' => $cleanedPhone]);
        }
    }

    public function rules(): array
    {
        return [
            'class_type' => 'required|in:discovery,fundive,refinement',
            'is_certified_diver' => 'boolean',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'participants' => 'required|array|min:1|max:45',
            'participants.*.name' => 'required|string|min:2|max:100|regex:/^[\pL\s\.\'\-]+$/u',
            'participants.*.birthdate' => 'nullable|date|before_or_equal:today',
            'participants.*.gender' => 'nullable|in:male,female,non_binary,prefer_not_to_say',
            'participants.*.age' => 'required_without:participants.*.birthdate|nullable|integer|min:8|max:85',
            'participants.*.health_condition' => 'nullable|string|max:1000',
            'participants.*.swimmer_status' => 'nullable|string|max:50',
            'contact_name' => 'required|string|min:2|max:100|regex:/^[\pL\s\.\'\-]+$/u',
            'contact_email' => 'required|email:rfc,filter|max:255',
            'contact_phone' => ['required', 'string', 'regex:/^(\+?63|0)9\d{9}$/'],
            'contact_facebook' => 'nullable|string|max:255',
            'pickup_option' => 'required|in:none,own,carpool',
            'pickup_location' => 'nullable|string|max:255',
            'boat_dive' => 'boolean',
            'payment_method' => 'required|in:gcash,bpi_bank_transfer,maya,bdo,unionbank,cash,other',
            'payment_stage' => 'required|in:downpayment,full',
            'payment_reference' => 'nullable|string|max:100',
            'admin_notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'Please enter a valid Philippine mobile number (e.g. 09171234567 or +639171234567).',
            'contact_email.email' => 'Please provide a valid email address.',
            'participants.*.age.required_without' => 'Please select a birthdate for every participant.',
            'participants.*.age.min' => 'Participant age must be at least 8 years old.',
            'participants.*.age.max' => 'Participant age cannot exceed 85 years old.',
            'participants.*.name.regex' => 'Participant names must contain letters only.',
            'contact_name.regex' => 'Contact name must contain letters only.',
        ];
    }
}
