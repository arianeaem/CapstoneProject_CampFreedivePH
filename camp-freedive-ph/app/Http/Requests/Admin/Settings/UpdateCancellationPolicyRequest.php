<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/SettingsController::updateCancellation(). */
class UpdateCancellationPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'full_refund_threshold_days' => 'required|integer|min:1|max:90',
            'reschedule_only_threshold_days' => 'required|integer|min:0|max:90',
        ];
    }
}
