<?php

namespace App\Http\Requests\Coach;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Coach/AvailabilityController::requestRelease(). */
class RequestReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'batch_id' => 'required|exists:batches,id',
            'dive_date' => 'required|date',
            'reason' => 'required|string|min:10|max:1000',
        ];
    }
}
