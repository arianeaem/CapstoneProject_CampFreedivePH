<?php

namespace App\Services;

use App\Models\Coach;
use App\Models\DeactivationRequest;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class DeactivationService
{
    /**
     * Ask to deactivate a coach (admin or owner).
     *
     * @throws Exception
     */
    public function propose(Coach $coach, User $requestedBy, ?string $reason = null): DeactivationRequest
    {
        if ($coach->status === 'inactive') {
            throw new Exception("Coach is already deactivated.");
        }

        if ($coach->status === 'pending_deactivation') {
            throw new Exception("A deactivation request is already pending for this coach.");
        }

        return DB::transaction(function () use ($coach, $requestedBy, $reason) {
            $coach->update(['status' => 'pending_deactivation']);

            return DeactivationRequest::create([
                'coach_id' => $coach->id,
                'requested_by' => $requestedBy->id,
                'status' => 'pending',
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Confirm the deactivation (owner only). Not allowed if the coach has future assignments.
     */
    public function confirm(DeactivationRequest $request, User $owner): array
    {
        $coach = $request->coach;

        // Does the coach have future assignments?
        $conflicts = $coach->activeAssignments()
            ->whereHas('batch', function ($q) {
                $q->where('start_date', '>=', now()->startOfDay());
            })
            ->with('batch')
            ->get();

        if ($conflicts->isNotEmpty()) {
            $batchCodes = $conflicts->pluck('batch.batch_code')->implode(', ');
            return [
                'success' => false,
                'message' => "Cannot deactivate coach. {$coach->full_name} has upcoming active assignments in batches: {$batchCodes}. Reassign or remove these sessions before deactivation.",
                'conflicts' => $conflicts,
            ];
        }

        DB::transaction(function () use ($request, $coach, $owner) {
            $coach->update(['status' => 'inactive']);
            $request->update([
                'status' => 'confirmed',
                'resolved_by' => $owner->id,
                'resolved_at' => now(),
            ]);
        });

        return [
            'success' => true,
            'message' => "Coach {$coach->full_name} has been successfully deactivated.",
        ];
    }

    /**
     * Dismiss a deactivation request (owner only).
     */
    public function dismiss(DeactivationRequest $request, User $owner): void
    {
        DB::transaction(function () use ($request, $owner) {
            $request->coach->update(['status' => 'active']);
            $request->update([
                'status' => 'dismissed',
                'resolved_by' => $owner->id,
                'resolved_at' => now(),
            ]);
        });
    }
}
