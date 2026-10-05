<?php

namespace App\Http\Requests\Coach;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Coach/AvailabilityController::bulkUpdate(). */
class BulkUpdateAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'dates' => 'required|array|min:1',
            'dates.*' => 'required|date',
            'status' => 'required|in:available,unavailable,remove',
        ];
    }
}
