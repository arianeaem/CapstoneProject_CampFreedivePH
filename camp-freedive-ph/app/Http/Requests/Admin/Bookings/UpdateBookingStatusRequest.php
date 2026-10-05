<?php

namespace App\Http\Requests\Admin\Bookings;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/BookingController::updateStatus(). */
class UpdateBookingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:confirmed,completed,rescheduled,no_show,cancelled_by_camp,cancelled_by_guest',
            'note' => 'nullable|string|max:500',
        ];
    }
}
