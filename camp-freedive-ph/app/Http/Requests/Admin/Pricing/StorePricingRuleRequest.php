<?php

namespace App\Http\Requests\Admin\Pricing;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/PricingRuleController::store(). */
class StorePricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:pricing_rules,name',
            'description' => 'nullable|string|max:1000',
            'rule_type' => 'required|in:demand,seasonality,lead_time',
            'condition_operator' => 'nullable|required_if:rule_type,lead_time|in:<=,>=,<,>,==',
            'condition_value' => [
            'required',
            'string',
            function ($attribute, $value, $fail) {
                $type = $this->input('rule_type');
                if ($type === 'demand' && !in_array($value, ['high', 'medium', 'low'])) {
                    $fail('The selected demand level is invalid. Must be High, Medium, or Low.');
                } elseif ($type === 'seasonality' && !in_array($value, ['peak', 'shoulder', 'off_peak'])) {
                    $fail('The selected season is invalid. Must be Peak, Shoulder, or Off-Peak.');
                } elseif ($type === 'lead_time' && (!is_numeric($value) || (int)$value < 0)) {
                    $fail('The lead time days must be a non-negative number.');
                }
            }
            ],
            'max_fill_percent' => 'nullable|integer|min:1|max:100',
            'applies_to' => 'required|in:all,discovery,fundive,refinement',
            'adjustment_type' => 'required|in:increase,decrease',
            'adjustment_method' => 'required|in:percentage,fixed',
            'adjustment_value' => 'required|numeric|min:0.01|max:50000',
            'priority' => 'nullable|integer|min:1|max:999',
            'status' => 'required|in:active,inactive',
        ];
    }
}
