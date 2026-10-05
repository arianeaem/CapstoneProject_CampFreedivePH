<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/SettingsController::updateDeposits(). */
class UpdateDepositsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'downpayment_carpool' => 'required|numeric|min:0|max:100000',
            'downpayment_own_transpo' => 'required|numeric|min:0|max:100000',
        ];
    }
}
