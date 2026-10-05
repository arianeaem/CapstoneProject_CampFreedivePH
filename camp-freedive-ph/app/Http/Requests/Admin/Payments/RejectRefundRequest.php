<?php

namespace App\Http\Requests\Admin\Payments;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/RefundController::reject(). */
class RejectRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'notes' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'Please provide a clear reason for rejecting this refund request.',
        ];
    }
}
