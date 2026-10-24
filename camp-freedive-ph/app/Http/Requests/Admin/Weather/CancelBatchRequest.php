<?php

namespace App\Http\Requests\Admin\Weather;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/WeatherSafetyController::cancel(). */
class CancelBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'cancellation_reason' => 'required|string|max:1000',
            // Has the camp activity already started? Missing means "unknown" (refunds wait for staff review)
            'activity_started' => 'nullable|string|in:no,yes,unknown',
        ];
    }
}
