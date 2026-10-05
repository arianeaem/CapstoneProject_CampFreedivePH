<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for BookingController::getPricingQuote(). */
class PricingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'class_type' => 'required|string|in:discovery,fundive,refinement',
            'start_date' => 'required|date',
            'is_certified_diver' => ['required_if:class_type,fundive', 'nullable', 'boolean'],
            'participants_count' => 'nullable|integer|min:1|max:10',
        ];
    }
}
