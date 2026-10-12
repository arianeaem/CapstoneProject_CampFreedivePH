<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/SettingsController::updatePrograms(). */
class UpdateProgramsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'base_price_discovery' => 'required|numeric|min:0|max:100000',
            'base_price_fundive_cert' => 'required|numeric|min:0|max:100000',
            'base_price_fundive_noncert' => 'required|numeric|min:0|max:100000',
            'base_price_refinement' => 'required|numeric|min:0|max:100000',
            'dynamic_pricing_cap_percent' => 'required|numeric|min:0|max:100',
            'min_price_discovery' => 'nullable|numeric|min:0|lte:base_price_discovery',
            'min_price_fundive_cert' => 'nullable|numeric|min:0|lte:base_price_fundive_cert',
            'min_price_fundive_noncert' => 'nullable|numeric|min:0|lte:base_price_fundive_noncert',
            'min_price_refinement' => 'nullable|numeric|min:0|lte:base_price_refinement',
            'discovery_inclusions' => 'nullable|string',
            'discovery_exclusions' => 'nullable|string',
            'fundive_inclusions' => 'nullable|string',
            'fundive_exclusions' => 'nullable|string',
            'refinement_inclusions' => 'nullable|string',
            'refinement_exclusions' => 'nullable|string',
        ];
    }
}
