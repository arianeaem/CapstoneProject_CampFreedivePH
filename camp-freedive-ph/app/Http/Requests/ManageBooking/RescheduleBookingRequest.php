<?php

namespace App\Http\Requests\ManageBooking;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/** Validation for ManageBookingController::reschedule(). */
class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'digits:4'],
            'requested_start_date' => ['required', 'date', 'after_or_equal:today'],
            'requested_end_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    $startDate = $this->input('requested_start_date');
                    if (!$startDate) {
                        return;
                    }
                    try {
                        $start = Carbon::parse($startDate)->startOfDay();
                        $end = Carbon::parse($value)->startOfDay();
                        if ($start->copy()->addDay()->format('Y-m-d') !== $end->format('Y-m-d')) {
                            $fail('The requested end date must be exactly one calendar day after the start date.');
                        }
                    } catch (\Throwable $e) {
                        $fail('The requested end date is invalid.');
                    }
                },
            ],
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
