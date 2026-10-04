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

    public function imminentCriticalRisk(Batch $batch): void
    {
        $diveStart = \Carbon\Carbon::parse($batch->start_date->toDateString(), 'Asia/Manila')->setTime(6, 30);
        $hoursUntilDive = now('Asia/Manila')->diffInHours($diveStart, false);

        if ($hoursUntilDive < 0 || $hoursUntilDive >= 18) {
            return;
        }

        $key = "admin:critical-risk-imminent:{$batch->id}:" . $batch->start_date->format('Y-m-d');
        if (!Cache::add($key, true, now()->addDay())) {
            return;
        }

        $this->notify(
            'URGENT: Critical Risk assessment required',
            "Batch {$batch->batch_code} is classified as Critical Risk and starts in approximately {$hoursUntilDive} hours. Complete the full operational assessment now and decide whether to issue a force-majeure notice to customers.",
            [
                'Batch' => $batch->batch_code,
                'Schedule' => "{$batch->start_date?->format('M d, Y')} - {$batch->end_date?->format('M d, Y')}",
                'Time to dive' => "{$hoursUntilDive} hours",
                'Required action' => 'Complete full assessment and decide on customer force-majeure notification',
            ],
            'critical',
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
