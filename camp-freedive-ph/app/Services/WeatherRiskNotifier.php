<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Booking;

/**
 * Weather notices sent less than 18 hours before a dive:
 * - Critical Risk: participants can reschedule for free, or cancel and request a downpayment refund that staff
 *   confirm (a rating alone never authorizes a refund). Owners/admins must decide.
 * - High Risk: everyone is told, but the dive is still on (no refund).
 * Each participant only gets each notice once per batch and dive date (saved in notification_logs).
 */
class WeatherRiskNotifier
{
    public const WINDOW_HOURS = 18;

    /** Bookings that are still going on the trip. */
    protected const ACTIVE_BOOKING_EXCLUDED = ['cancelled', 'cancelled_by_camp', 'cancelled_by_guest', 'pending_downpayment', 'paid_unallocated', 'completed', 'no_show'];

    public function __construct(protected AdminNotificationService $admin)
    {
    }

    public function handle(Batch $batch, string $classification): void
    {
        $level = match ($classification) {
            'Critical Risk' => 'critical',
            'High Risk' => 'high',
            default => null,
        };
        if (!$level || !$batch->start_date || in_array($batch->status, ['cancelled_by_camp', 'completed'], true)) {
            return;
        }

        $hours = AdminNotificationService::hoursUntilDive($batch);
        if ($hours < 0 || $hours >= self::WINDOW_HOURS) {
            return;
        }

        $guestsNotified = $this->notifyGuests($batch, $level, $hours);

        $level === 'critical'
            ? $this->admin->imminentCriticalRisk($batch, $guestsNotified)
            : $this->admin->imminentHighRisk($batch, $guestsNotified);
    }

    protected function notifyGuests(Batch $batch, string $level, int $hours): int
    {
        $bookings = Booking::where('batch_id', $batch->id)
            ->whereNotIn('status', self::ACTIVE_BOOKING_EXCLUDED)
            ->whereNotNull('contact_email')
            ->get();

        $outbox = app(NotificationOutbox::class);
        foreach ($bookings as $booking) {
            $outbox->weatherRisk($booking, $batch, $level, $hours);
        }

        return $bookings->count();
    }
}
