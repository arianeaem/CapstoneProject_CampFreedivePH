<?php

namespace App\Services;

use App\Mail\AdminOperationalNotificationMail;
use App\Models\Batch;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

class AdminNotificationService
{
    public function notify(
        string $eventTitle,
        string $message,
        array $details = [],
        string $severity = 'info',
    ): void {
        $admins = User::whereIn('role', ['admin', 'owner'])
            ->where('status', 'active')
            ->whereNotNull('email')
            ->get();

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->send(
                    new AdminOperationalNotificationMail($eventTitle, $message, $details, $severity)
                );
            } catch (\Throwable $e) {
                Log::warning("Failed to send admin notification to {$admin->email}: " . $e->getMessage());
            }
        }
    }

    public function newBooking(\App\Models\Booking $booking): void
    {
        $this->notify(
            'New booking received',
            "Booking {$booking->booking_number} was created for {$booking->contact_name}.",
            ['Booking' => $booking->booking_number, 'Customer' => $booking->contact_name, 'Schedule' => "{$booking->start_date?->format('M d, Y')} - {$booking->end_date?->format('M d, Y')}", 'Status' => $booking->status],
        );
    }

    public function customerRequest(string $type, \App\Models\Booking $booking, string $reason): void
    {
        $this->notify(
            "Customer {$type} request",
            "Booking {$booking->booking_number} has a new customer {$type} request.",
            ['Booking' => $booking->booking_number, 'Customer' => $booking->contact_name, 'Reason' => $reason],
            'warning',
        );
    }

    public function risk(Batch $batch, string $classification): void
    {
        $key = "admin:risk-alert:{$batch->id}:{$classification}:" . now()->format('Y-m-d-H');
        if (!Cache::add($key, true, now()->addHours(2))) {
            return;
        }

        $this->notify(
            "{$classification} weather alert",
            "Batch {$batch->batch_code} has been classified as {$classification}. Review the weather assessment and operational action.",
            ['Batch' => $batch->batch_code, 'Schedule' => "{$batch->start_date?->format('M d, Y')} - {$batch->end_date?->format('M d, Y')}", 'Classification' => $classification],
            $classification === 'Critical Risk' ? 'critical' : 'high',
        );
    }

    /** Hours from now until the batch's first dive (06:30 on day 1, Manila time). */
    public static function hoursUntilDive(Batch $batch): int
    {
        $diveStart = \Carbon\Carbon::parse($batch->start_date->toDateString(), 'Asia/Manila')->setTime(6, 30);

        return (int) now('Asia/Manila')->diffInHours($diveStart, false);
    }

    /**
     * Critical Risk less than 18 hours before the dive: tell owners/admins what guests were offered
     * (free reschedule or full downpayment refund) and that they must decide on the batch.
     */
    public function imminentCriticalRisk(Batch $batch, int $guestsNotified = 0): void
    {
        $hoursUntilDive = self::hoursUntilDive($batch);
        if ($hoursUntilDive < 0 || $hoursUntilDive >= 18) {
            return;
        }

        $key = "admin:critical-risk-imminent:{$batch->id}:" . $batch->start_date->format('Y-m-d');
        if (!Cache::add($key, true, now()->addDay())) {
            return;
        }

        $this->notify(
            "URGENT: Critical Risk for {$batch->batch_code} - starts in about {$hoursUntilDive} hours",
            "The latest weather and sea check rates batch {$batch->batch_code} as Critical Risk, and the dive starts in about {$hoursUntilDive} hours. "
                . "Guests in this batch have been emailed that they can reschedule for free or cancel with a full refund of their downpayment (force-majeure). "
                . "Please review the conditions in Safety Monitoring now and decide whether to cancel the batch.",
            [
                'Batch' => $batch->batch_code,
                'Dive dates' => "{$batch->start_date?->format('M d, Y')} - {$batch->end_date?->format('M d, Y')}",
                'Starts in' => "About {$hoursUntilDive} hours",
                'Guests emailed' => (string) $guestsNotified,
                'What to do' => 'Check Safety Monitoring, then cancel the batch or confirm it is safe to go',
            ],
            'critical',
        );
    }

    /**
     * High Risk less than 18 hours before the dive: heads-up only. Guests were told the dive is still planned.
     */
    public function imminentHighRisk(Batch $batch, int $guestsNotified = 0): void
    {
        $hoursUntilDive = self::hoursUntilDive($batch);
        if ($hoursUntilDive < 0 || $hoursUntilDive >= 18) {
            return;
        }

        $key = "admin:high-risk-imminent:{$batch->id}:" . $batch->start_date->format('Y-m-d');
        if (!Cache::add($key, true, now()->addDay())) {
            return;
        }

        $this->notify(
            "High Risk for {$batch->batch_code} - starts in about {$hoursUntilDive} hours",
            "The latest weather and sea check rates batch {$batch->batch_code} as High Risk, and the dive starts in about {$hoursUntilDive} hours. "
                . "Guests have been told the dive is still planned and that coaches will take extra safety steps. No free cancellation or refund was offered. "
                . "Please brief the coaches and check Safety Monitoring again before departure.",
            [
                'Batch' => $batch->batch_code,
                'Dive dates' => "{$batch->start_date?->format('M d, Y')} - {$batch->end_date?->format('M d, Y')}",
                'Starts in' => "About {$hoursUntilDive} hours",
                'Guests emailed' => (string) $guestsNotified,
                'What to do' => 'Brief coaches on extra safety steps and recheck conditions before departure',
            ],
            'high',
        );
    }

    public function coachMatchingNeeded(Batch $batch, int $participants, int $assigned): void
    {
        $this->notify(
            'Coach matching required',
            "Batch {$batch->batch_code} starts within 24 hours and still needs coach matching.",
            ['Batch' => $batch->batch_code, 'Participants' => (string) $participants, 'Assigned students' => (string) $assigned, 'Unmatched students' => (string) max(0, $participants - $assigned)],
            'critical',
        );
    }

    public function coachEmergencyRelease(\App\Models\AssignmentReleaseRequest $release): void
    {
        $this->notify(
            'Coach emergency release requested',
            "Coach {$release->coach?->name} requested an emergency release from batch {$release->batch?->batch_code}.",
            ['Coach' => $release->coach?->name ?? 'Coach', 'Batch' => $release->batch?->batch_code ?? 'Batch', 'Dive date' => $release->dive_date?->format('M d, Y'), 'Reason' => $release->reason],
            'critical',
        );
    }
}
