<?php

namespace App\Http\Requests\Admin\Pricing;

use Illuminate\Validation\Rule;

/** Validation for Admin/PricingRuleController::update(): same as creating, but the rule may keep its own name. */
class UpdatePricingRuleRequest extends StorePricingRuleRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['required', 'string', 'max:255', Rule::unique('pricing_rules')->ignore($this->route('rule')?->id)];
        $rules['status'] = 'required|in:active,inactive';

        return $rules;
    }
}
