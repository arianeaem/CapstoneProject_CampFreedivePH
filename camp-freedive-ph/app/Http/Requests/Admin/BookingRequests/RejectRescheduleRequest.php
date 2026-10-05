<?php

namespace App\Http\Requests\Admin\BookingRequests;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/BookingRequestController::rejectReschedule(). */
class RejectRescheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'admin_notes' => 'nullable|string|max:500',
        ];
    }
}
