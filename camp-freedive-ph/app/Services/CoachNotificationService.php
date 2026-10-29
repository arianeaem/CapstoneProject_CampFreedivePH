<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\CoachOpening;
use App\Models\User;
use Illuminate\Support\Collection;

class CoachNotificationService
{
    public function notifyBatchCoaches(
        Batch $batch,
        string $eventTitle,
        string $message,
        ?string $bookingNumber = null,
        ?Collection $coaches = null,
    ): void {
        $coaches ??= $batch->fresh()->assigned_coaches;

        foreach ($coaches->unique('id') as $coach) {
            $this->send($coach, $batch, $eventTitle, $message, $bookingNumber);
        }
    }

    public function notifyOpening(CoachOpening $opening): void
    {
        $batch = $opening->batch()->first();
        if (!$batch) {
            return;
        }

        $coaches = User::where('role', 'coach')
            ->where('status', 'active')
            ->whereNotNull('email')
            ->get();

        $this->notifyBatchCoaches(
            $batch,
            'New open coaching slot',
            "An open coaching slot has been shared by Camp Administration for {$opening->dive_date->format('M d, Y')}. Review the Coach Portal to volunteer.",
            null,
            $coaches,
        );
    }

    private function send(
        User $coach,
        Batch $batch,
        string $eventTitle,
        string $message,
        ?string $bookingNumber,
    ): void {
        app(NotificationOutbox::class)->coachNotification($coach, $batch, $eventTitle, $message, $bookingNumber);
    }
}
