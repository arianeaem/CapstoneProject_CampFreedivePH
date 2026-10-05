<?php

namespace App\Http\Requests\Admin\Batches;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/BatchManagementController::store(). */
class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'batch_number' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:255',
            'batch_code' => 'nullable|string|max:100',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'risk_classification' => 'nullable|string|in:very_safe,safe,moderate,high_risk,critical_risk',
            'capacity_note' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'booking_ids' => 'nullable|array',
            'booking_ids.*' => 'exists:bookings,id',
        ];
    }
}
