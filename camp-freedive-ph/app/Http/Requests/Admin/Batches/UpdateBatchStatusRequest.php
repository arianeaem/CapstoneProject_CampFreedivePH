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
        ];
    }
}
