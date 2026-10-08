<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\Concerns\ChecksMinorsAndContact;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation for BookingController::store(). */
class StorePublicBookingRequest extends FormRequest
{
    use ChecksMinorsAndContact;

    public function authorize(): bool
    {
        return true; // access is checked by route middleware
    }

    public function rules(): array
    {
        $settingService = app(\App\Services\SystemSettingService::class);
        $pickupPoints = $settingService->get('addons.pickup_locations', [
            ['id' => 'monumento', 'name' => 'Monumento Hypermarket - 2:30 AM'],
            ['id' => 'tiendesitas', 'name' => 'Shell Tiendesitas - 3:00 AM'],
            ['id' => 'market_market', 'name' => 'Market Market Taxi Bay - 3:40 AM'],
            ['id' => 'alabang', 'name' => 'Alabang Starmall - 4:15 AM'],
            ['id' => 'sto_tomas', 'name' => 'Sto Tomas Exit - 5:30 AM'],
        ]);
        $validPickupLocations = collect($pickupPoints)
            ->map(fn($p) => [$p['id'] ?? null, $p['name'] ?? null])
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();

        $allowedSuffixes = ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V', 'None'];

        return [
            'class_type' => 'required|string|in:discovery,fundive,refinement',
            'is_certified_diver' => ['required_if:class_type,fundive', 'nullable', 'boolean'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    $startDate = $this->input('start_date');
                    if (!$startDate) {
                        return;
                    }
                    try {
                        $start = Carbon::parse($startDate)->startOfDay();
                        $end = Carbon::parse($value)->startOfDay();
                        if ($start->copy()->addDay()->format('Y-m-d') !== $end->format('Y-m-d')) {
                            $fail('The end date must be exactly one calendar day after the start date.');
                        }
                    } catch (\Throwable $e) {
                        $fail('The end date is invalid.');
                    }
                },
            ],
            'participants' => 'required|array|min:1|max:10',
            'participants.*.name' => 'nullable|string|max:255',
            'participants.*.first_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'participants.*.middle_name' => ['nullable', 'string', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'participants.*.no_middle_name' => 'nullable|boolean',
            'participants.*.last_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'participants.*.suffix' => ['nullable', 'string', Rule::in($allowedSuffixes)],
            'participants.*.birthdate' => 'nullable|date|before_or_equal:today',
            'participants.*.gender' => 'nullable|in:male,female,non_binary,prefer_not_to_say',
            'participants.*.age' => 'required_without:participants.*.birthdate|nullable|integer|min:8|max:85',
            'participants.*.health_condition' => 'nullable|string|max:500',
            'participants.*.swimmer_status' => 'nullable|string|in:non_swimmer,beginner,intermediate,advanced,swimmer,casual_swimmer,confident_swimmer',
            'contact_name' => 'nullable|string|max:255',
            'contact_first_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'contact_middle_name' => ['nullable', 'string', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'contact_no_middle_name' => 'nullable|boolean',
            'contact_last_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'contact_suffix' => ['nullable', 'string', Rule::in($allowedSuffixes)],
            'contact_email' => 'required|email|max:255',
            'contact_phone' => ['required', 'string', 'regex:/^(\+?63|0)?[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}$/'],
            'contact_facebook' => 'nullable|string|max:255',
            'pickup_option' => 'required|string|in:carpool,own',
            'pickup_location' => [
                'required_if:pickup_option,carpool',
                'nullable',
                'string',
                Rule::in($validPickupLocations),
            ],
            'boat_dive' => 'nullable|boolean',
            'confirmation_ack' => 'required|accepted',
            'has_agreed_to_terms' => 'required|accepted',
            'payment_method' => ['nullable', 'string', 'in:paymongo'],
        ] + $this->minorConsentRules();
    }

    /** Primary contact 18+, participants 8-85, guardian consent for under-18s. */
    public function withValidator($validator): void
    {
        $this->checkMinorsAndContact($validator);
    }

    public function messages(): array
    {
        return [
            'pickup_location.required_if' => 'Please select a carpool pickup location.',
            'pickup_location.in' => 'Please select a valid configured pickup location.',
            'contact_phone.regex' => 'Please enter a valid Philippine mobile number (e.g. +63 917-123-4567 or 09171234567).',
            'participants.*.first_name.required' => 'Participant first name is required.',
            'participants.*.first_name.min' => 'Participant first name must be at least 2 characters.',
            'participants.*.first_name.regex' => 'Participant first name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'participants.*.middle_name.regex' => 'Participant middle name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'participants.*.last_name.required' => 'Participant last name is required.',
            'participants.*.last_name.min' => 'Participant last name must be at least 2 characters.',
            'participants.*.last_name.regex' => 'Participant last name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'contact_first_name.required' => 'Primary contact first name is required.',
            'contact_first_name.min' => 'Primary contact first name must be at least 2 characters.',
            'contact_first_name.regex' => 'Primary contact first name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'contact_middle_name.regex' => 'Primary contact middle name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'contact_last_name.required' => 'Primary contact last name is required.',
            'contact_last_name.min' => 'Primary contact last name must be at least 2 characters.',
            'contact_last_name.regex' => 'Primary contact last name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'confirmation_ack.accepted' => 'You must confirm that all details provided are accurate.',
            'has_agreed_to_terms.accepted' => 'You must agree to the Terms & Conditions and Privacy Policy to complete your booking.',
        ];
    }
}
