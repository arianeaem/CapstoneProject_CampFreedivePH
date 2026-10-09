<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Api/WeatherPreviewController::preview(). */
class WeatherPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'start_date' => 'required|date',
        ];
    }
}
