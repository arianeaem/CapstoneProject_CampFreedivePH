<?php

namespace App\Http\Requests\Admin\Payments;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/RefundController::forfeit(). */
class ForfeitPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'forfeit_reason' => ['required', 'in:cancellation_outside_policy_window,customer_no_show,unapproved_late_withdrawal,custom_administrative_decision'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
