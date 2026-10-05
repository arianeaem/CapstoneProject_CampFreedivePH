<?php

namespace App\Http\Requests\Admin\Coaches;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/CoachRosterController::reassignStudent(). */
class ReassignStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'participant_id' => 'required|exists:booking_participants,id',
            'new_coach_id' => 'required|exists:users,id',
            'reason' => 'required|string|max:500',
        ];
    }
}
