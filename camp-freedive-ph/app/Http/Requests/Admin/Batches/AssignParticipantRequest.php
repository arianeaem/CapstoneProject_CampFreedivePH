<?php

namespace App\Http\Requests\Admin\Batches;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/BatchManagementController::assignParticipant(). */
class AssignParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'participant_id' => 'required|exists:booking_participants,id',
            'coach_id' => 'nullable|exists:users,id',
        ];
    }
}
