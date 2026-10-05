<?php

namespace App\Http\Requests\Admin\Coaches;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/CoachMatchingController::broadcastOpening(). */
class BroadcastOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'batch_id' => 'required|exists:batches,id',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
