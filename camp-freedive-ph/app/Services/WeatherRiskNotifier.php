<?php

namespace App\Services;

use App\Mail\WeatherRiskNoticeMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Weather notices sent less than 18 hours before a dive:
 * - Critical Risk: participants can reschedule for free or cancel with a full downpayment refund. Owners/admins must decide.
 * - High Risk: everyone is told, but the dive is still on (no refund).
 * Each participant only gets each notice once per batch and dive date (saved in notification_logs).
 */
class WeatherRiskNotifier
{
    public const WINDOW_HOURS = 18;

    /** Bookings that are still going on the trip. */
    protected const ACTIVE_BOOKING_EXCLUDED = ['cancelled', 'cancelled_by_camp', 'cancelled_by_guest', 'pending_downpayment', 'completed', 'no_show'];

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

        $sent = 0;
        foreach ($bookings as $booking) {
            $mail = new WeatherRiskNoticeMail($booking, $batch, $level, max(0, $hours));
            $subject = $mail->envelope()->subject;

            // Once per participant per batch, rating and dive date
            $alreadySent = NotificationLog::where('batch_id', $batch->id)
                ->where('booking_id', $booking->id)
                ->where('subject', $subject)
                ->exists();
            if ($alreadySent) {
                $sent++;
                continue;
            }

            try {
                Mail::to($booking->contact_email)->send($mail);
                NotificationLog::create([
                    'batch_id' => $batch->id,
                    'booking_id' => $booking->id,
                    'recipient_email' => $booking->contact_email,
                    'recipient_name' => $booking->contact_name,
                    'subject' => $subject,
                    'message_body' => $level === 'critical'
                        ? 'Critical Risk notice: participant may reschedule for free or cancel with a full downpayment refund.'
                        : 'High Risk notice: dive still planned, extra safety steps.',
                    'channel' => 'email',
                    'sent_at' => now(),
                ]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("Failed to send {$level} weather notice to {$booking->contact_email}: " . $e->getMessage());
            }
        }

        return $sent;
    }
}
