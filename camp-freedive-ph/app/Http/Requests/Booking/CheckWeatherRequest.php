<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for BookingController::checkWeather(). */
class CheckWeatherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ];
    }
}
