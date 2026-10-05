<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/SettingsController::updateOperations(). */
class UpdateOperationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'max_batch_capacity' => 'required|integer|min:5|max:200',
            'coach_student_ratio' => 'required|integer|min:1|max:20',
            'min_coaches_per_batch' => 'required|integer|min:1|max:10',
        ];
    }
}
