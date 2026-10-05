<?php

namespace App\Http\Requests\Admin\Coaches;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for CoachMatchingController::assign() and ::batchAssign().
 * The coach pages send 3 kinds of data: balanced assignments, a list of
 * students for one coach, or just a list of coaches for a batch.
 */
class AssignCoachesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware
    }

    public function rules(): array
    {
        if ($this->has('assignments')) {
            return [
                'batch_id' => 'required|exists:batches,id',
                'assignments' => 'required|array|min:1',
                'exception_note' => 'nullable|string|max:500',
            ];
        }

        if ($this->has('participant_ids')) {
            return [
                'participant_ids' => 'required|array|min:1',
                'participant_ids.*' => 'exists:booking_participants,id',
                'coach_id' => 'required|exists:users,id',
                'batch_id' => 'required|exists:batches,id',
            ];
        }

        return [
            'batch_id' => 'required|exists:batches,id',
            'coach_ids' => 'nullable|array',
            'coach_ids.*' => 'exists:users,id',
            'coach_id' => 'nullable|exists:users,id',
        ];
    }
}
