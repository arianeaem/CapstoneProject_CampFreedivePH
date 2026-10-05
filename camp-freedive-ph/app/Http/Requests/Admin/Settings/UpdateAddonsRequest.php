<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/SettingsController::updateAddons(). */
class UpdateAddonsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'carpool_fee_per_head' => 'required|numeric|min:0|max:100000',
            'boat_dive_fee_per_head' => 'required|numeric|min:0|max:100000',
            'lgu_tourism_pass_fee' => 'required|numeric|min:0|max:100000',
            'environmental_fee' => 'required|numeric|min:0|max:100000',
            'pickup_locations' => 'nullable|array',
            'pickup_locations.*.id' => 'required|string',
            'pickup_locations.*.name' => 'required|string',
            'pickup_locations.*.time' => 'required|string',
            'pickup_locations.*.address' => 'nullable|string',
        ];
    }
}
