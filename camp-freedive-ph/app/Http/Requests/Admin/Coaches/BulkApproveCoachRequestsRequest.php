<?php

namespace App\Http\Requests\Admin\Coaches;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/CoachMatchingController::bulkApproveRequests(). */
class BulkApproveCoachRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'request_ids' => 'required|array|min:1',
            'request_ids.*' => 'exists:coach_requests,id',
        ];
    }
}
