<?php

namespace App\Http\Requests\Admin\Batches;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/BatchManagementController::updateStatus(). */
class UpdateBatchStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'status' => 'required|string|in:confirmed,completed,rescheduled,cancelled_by_camp',
            'note' => 'nullable|string|max:1000',
            // Camp cancellation only (optional for older callers: missing means "unknown", which goes to staff review)
            'cancellation_reason_category' => 'nullable|string|in:weather_pre_dive,weather_in_progress,camp_operational',
            'activity_started' => 'nullable|string|in:no,yes,unknown',
        ];
    }
}
