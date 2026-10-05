<?php

namespace App\Http\Requests\Admin\Batches;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/BatchManagementController::moveBooking(). */
class MoveBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'booking_id' => 'required|exists:bookings,id',
            'target_batch_id' => 'nullable|exists:batches,id',
            'reason' => 'nullable|string|max:500',
        ];
    }
}
