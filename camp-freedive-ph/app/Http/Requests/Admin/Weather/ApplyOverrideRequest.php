<?php

namespace App\Http\Requests\Admin\Weather;

use App\Http\Controllers\Admin\WeatherSafetyController;
use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/WeatherSafetyController::override(). */
class ApplyOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'tcws_signal' => 'nullable|integer|min:0|max:5',
            'gale_warning' => 'nullable|boolean',
            'thunderstorm_advisory' => 'nullable|boolean',
            'typhoon_within_distance' => 'nullable|boolean',
            'tsunami_warning' => 'nullable|boolean',
            'other_hazard' => 'nullable|string|in:' . implode(',', array_keys(WeatherSafetyController::OTHER_HAZARDS)),
            'other_hazard_detail' => 'nullable|required_if:other_hazard,other|string|max:150',
            'reason' => 'required|string|max:1000',
            'cancel_batch' => 'nullable|boolean',
        ];
    }
}
