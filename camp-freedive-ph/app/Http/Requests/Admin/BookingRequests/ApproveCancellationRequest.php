<?php

namespace App\Http\Requests\Admin\BookingRequests;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/BookingRequestController::approveCancellation(). */
class ApproveCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'action_type' => 'nullable|string|in:policy_refund,full_refund,forfeit',
            'refund_amount' => 'nullable|numeric|min:0',
            'admin_notes' => 'nullable|string|max:500',
        ];
    }
}
