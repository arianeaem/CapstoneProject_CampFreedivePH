<?php

namespace App\Services;

use App\Jobs\SendCheckoutNotification;
use App\Mail\AdminOperationalNotificationMail;
use App\Mail\BatchRescheduledMail;
use App\Mail\BatchWeatherCancellationMail;
use App\Mail\BookingDetailsUpdatedMail;
use App\Mail\CancellationApprovedMail;
use App\Mail\CancellationRejectedMail;
use App\Mail\CancellationRequestedMail;
use App\Mail\CoachScheduleNotificationMail;
use App\Mail\RescheduleApprovedMail;
use App\Mail\RescheduleRejectedMail;
use App\Mail\RescheduleRequestedMail;
use App\Mail\WeatherRiskNoticeMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\CheckoutNotification;
use App\Models\PaymentException;
use App\Models\RescheduleRequest;
use App\Models\User;
use App\Services\Checkout\CheckoutNotifier;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * B6 general-purpose notification outbox — extends the checkout outbox (checkout_notifications) to all notification
 * types. Uses the same table, claim-based delivery, bounded retries, and deduplication, dispatched through the same
 * SendCheckoutNotification job and CheckoutNotifier::deliver().
 *
 * Recording and dispatch follow the same pattern as CheckoutNotifier::record() + dispatchDue():
 *  1. Record the intent to notify in a DB transaction (insertOrIgnore for dedup).
 *  2. Dispatch the queued delivery job afterCommit.
 *  3. The job calls CheckoutNotifier::deliver() which handles claims, retries, and the mailable lookup.
 *
 * The mailable() method in CheckoutNotifier is extended by this class: for new kinds, this service's buildMailable()
 * is used instead. CheckoutNotifier delegates to it for kinds it doesn't know.
 */
class NotificationOutbox
{
    public function __construct(
        private readonly CheckoutNotifier $notifier,
    ) {}

    // --- Guest request notifications ---

    public function cancellationRequested(Booking $booking, CancellationRequest $request): void
    {
        $this->recordForBooking($booking, CheckoutNotification::GUEST_CANCELLATION_REQUESTED,
            eventKey: "cancel_req:{$request->id}",
            context: ['cancellation_request_id' => $request->id]);
    }

    public function cancellationApproved(Booking $booking, CancellationRequest $request, float $refundAmount, bool $isForfeited, bool $refundUnderReview = false): void
    {
        $this->recordForBooking($booking, CheckoutNotification::GUEST_CANCELLATION_APPROVED,
            eventKey: "cancel_approve:{$request->id}",
            context: ['cancellation_request_id' => $request->id, 'refund_amount' => $refundAmount, 'is_forfeited' => $isForfeited, 'refund_under_review' => $refundUnderReview]);
    }

    public function cancellationRejected(Booking $booking, CancellationRequest $request, string $reason): void
    {
        $this->recordForBooking($booking, CheckoutNotification::GUEST_CANCELLATION_REJECTED,
            eventKey: "cancel_reject:{$request->id}",
            context: ['cancellation_request_id' => $request->id, 'reason' => $reason]);
    }

    public function rescheduleRequested(Booking $booking, RescheduleRequest $request): void
    {
        $this->recordForBooking($booking, CheckoutNotification::GUEST_RESCHEDULE_REQUESTED,
            eventKey: "resched_req:{$request->id}",
            context: ['reschedule_request_id' => $request->id]);
    }

    public function rescheduleApproved(Booking $booking, RescheduleRequest $request): void
    {
        $this->recordForBooking($booking, CheckoutNotification::GUEST_RESCHEDULE_APPROVED,
            eventKey: "resched_approve:{$request->id}",
            context: ['reschedule_request_id' => $request->id]);
    }

    public function rescheduleRejected(Booking $booking, RescheduleRequest $request, string $reason): void
    {
        $this->recordForBooking($booking, CheckoutNotification::GUEST_RESCHEDULE_REJECTED,
            eventKey: "resched_reject:{$request->id}",
            context: ['reschedule_request_id' => $request->id, 'reason' => $reason]);
    }

    // --- Batch lifecycle ---

    public function batchCancelled(Booking $booking, Batch $batch, string $note, string $category): void
    {
        $this->recordForBookingAndBatch($booking, $batch, CheckoutNotification::GUEST_BATCH_CANCELLED,
            eventKey: "batch_cancel:{$batch->id}:{$booking->id}",
            context: ['note' => $note, 'category' => $category]);
    }

    public function batchRescheduled(Booking $booking, Batch $batch, ?string $note): void
    {
        $this->recordForBookingAndBatch($booking, $batch, CheckoutNotification::GUEST_BATCH_RESCHEDULED,
            eventKey: "batch_resched:{$batch->id}:{$booking->id}",
            context: ['note' => $note]);
    }

    public function bookingUpdated(Booking $booking, array $changes): void
    {
        $this->recordForBooking($booking, CheckoutNotification::GUEST_BOOKING_UPDATED,
            context: ['changes' => $changes]);
    }

    // --- Weather ---

    public function weatherRisk(Booking $booking, Batch $batch, string $level, int $hours): void
    {
        $kind = $level === 'critical' ? CheckoutNotification::GUEST_WEATHER_CRITICAL : CheckoutNotification::GUEST_WEATHER_HIGH;
        $this->recordForBookingAndBatch($booking, $batch, $kind,
            eventKey: "weather:{$level}:{$batch->id}:{$booking->id}:{$batch->start_date->format('Y-m-d')}",
            context: ['level' => $level, 'hours' => $hours]);
    }

    // --- Staff admin alerts ---

    public function staffAlert(string $title, string $message, array $details = [], string $severity = 'info', ?Booking $booking = null, ?Batch $batch = null, ?PaymentException $exception = null, ?string $eventKey = null): void
    {
        $staff = User::whereIn('role', ['admin', 'owner'])->where('status', 'active')->whereNotNull('email')->pluck('email')->unique();
        $now = now();
        $rows = $staff->map(fn (string $email) => $this->outboxRow(
            kind: CheckoutNotification::STAFF_ADMIN_ALERT,
            email: $email,
            bookingId: $booking?->id,
            batchId: $batch?->id,
            paymentExceptionId: $exception?->id,
            eventKey: $eventKey,
            context: ['title' => $title, 'message' => $message, 'details' => $details, 'severity' => $severity],
            now: $now,
        ))->all();

        if ($rows === []) {
            return;
        }

        try {
            DB::transaction(fn () => CheckoutNotification::query()->insertOrIgnore($rows));
            $this->dispatchPending($booking?->id, $batch?->id, $exception?->id);
        } catch (Throwable $e) {
            Log::error('NotificationOutbox: staff alert recording failed.', ['title' => $title, 'exception' => class_basename($e)]);
        }
    }

    // --- Coach notifications ---

    public function coachNotification(User $coach, Batch $batch, string $title, string $message, ?string $bookingNumber = null): void
    {
        if (! $coach->email) {
            return;
        }

        $now = now();
        $row = $this->outboxRow(
            kind: CheckoutNotification::STAFF_COACH_SCHEDULE,
            email: $coach->email,
            batchId: $batch->id,
            eventKey: "coach:{$batch->id}:{$coach->id}:" . md5($title),
            context: ['title' => $title, 'message' => $message, 'booking_number' => $bookingNumber],
            now: $now,
        );

        try {
            DB::transaction(fn () => CheckoutNotification::query()->insertOrIgnore([$row]));
            $this->dispatchPending(batchId: $batch->id);
        } catch (Throwable $e) {
            Log::error('NotificationOutbox: coach notification recording failed.', ['coach_id' => $coach->id, 'exception' => class_basename($e)]);
        }
    }

    /**
     * Build the mailable for a B6 outbox row. Called by CheckoutNotifier::deliver() for kinds it doesn't know.
     * Returns null if the notification should be skipped (e.g. booking no longer active).
     */
    public function buildMailable(CheckoutNotification $n): ?Mailable
    {
        $booking = $n->booking;
        $batch = $n->batch;
        $ctx = $n->context ?? [];

        return match ($n->kind) {
            CheckoutNotification::GUEST_CANCELLATION_REQUESTED => $booking
                ? new CancellationRequestedMail($booking, CancellationRequest::find($ctx['cancellation_request_id'] ?? 0) ?? $booking->cancellationRequests()->latest()->first())
                : null,
            CheckoutNotification::GUEST_CANCELLATION_APPROVED => $booking
                ? new CancellationApprovedMail($booking,
                    CancellationRequest::findOrFail($ctx['cancellation_request_id']),
                    (float) ($ctx['refund_amount'] ?? 0),
                    (bool) ($ctx['is_forfeited'] ?? false),
                    (bool) ($ctx['refund_under_review'] ?? false))
                : null,
            CheckoutNotification::GUEST_CANCELLATION_REJECTED => $booking
                ? new CancellationRejectedMail($booking,
                    CancellationRequest::findOrFail($ctx['cancellation_request_id']),
                    $ctx['reason'] ?? '')
                : null,
            CheckoutNotification::GUEST_RESCHEDULE_REQUESTED => $booking
                ? new RescheduleRequestedMail($booking, RescheduleRequest::find($ctx['reschedule_request_id'] ?? 0) ?? $booking->rescheduleRequests()->latest()->first())
                : null,
            CheckoutNotification::GUEST_RESCHEDULE_APPROVED => $booking
                ? new RescheduleApprovedMail($booking, RescheduleRequest::findOrFail($ctx['reschedule_request_id']))
                : null,
            CheckoutNotification::GUEST_RESCHEDULE_REJECTED => $booking
                ? new RescheduleRejectedMail($booking, RescheduleRequest::findOrFail($ctx['reschedule_request_id']), $ctx['reason'] ?? '')
                : null,
            CheckoutNotification::GUEST_BATCH_CANCELLED => $booking && $batch
                ? new BatchWeatherCancellationMail($booking, $batch, $ctx['note'] ?? '', $ctx['category'] ?? 'weather')
                : null,
            CheckoutNotification::GUEST_BATCH_RESCHEDULED => $booking && $batch
                ? new BatchRescheduledMail($booking, $batch, $ctx['note'] ?? null)
                : null,
            CheckoutNotification::GUEST_BOOKING_UPDATED => $booking
                ? new BookingDetailsUpdatedMail($booking, $ctx['changes'] ?? [])
                : null,
            CheckoutNotification::GUEST_WEATHER_CRITICAL,
            CheckoutNotification::GUEST_WEATHER_HIGH => $booking && $batch
                ? new WeatherRiskNoticeMail($booking, $batch, $ctx['level'] ?? 'high', max(0, $ctx['hours'] ?? 0))
                : null,
            CheckoutNotification::STAFF_ADMIN_ALERT => new AdminOperationalNotificationMail(
                $ctx['title'] ?? 'Notification',
                $ctx['message'] ?? '',
                $ctx['details'] ?? [],
                $ctx['severity'] ?? 'info',
            ),
            CheckoutNotification::STAFF_COACH_SCHEDULE => $batch
                ? new CoachScheduleNotificationMail($batch, $ctx['title'] ?? '', $ctx['message'] ?? '', $ctx['booking_number'] ?? null)
                : null,
            default => null,
        };
    }

    // --- Internal helpers ---

    private function recordForBooking(Booking $booking, string $kind, ?string $eventKey = null, array $context = []): void
    {
        $email = $booking->contact_email;
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $now = now();
        $row = $this->outboxRow(kind: $kind, email: $email, bookingId: $booking->id, eventKey: $eventKey, context: $context ?: null, now: $now);

        try {
            DB::transaction(fn () => CheckoutNotification::query()->insertOrIgnore([$row]));
            $this->dispatchPending($booking->id);
        } catch (Throwable $e) {
            Log::error('NotificationOutbox: recording failed.', ['kind' => $kind, 'booking_id' => $booking->id, 'exception' => class_basename($e)]);
        }
    }

    private function recordForBookingAndBatch(Booking $booking, Batch $batch, string $kind, ?string $eventKey = null, array $context = []): void
    {
        $email = $booking->contact_email;
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $now = now();
        $row = $this->outboxRow(kind: $kind, email: $email, bookingId: $booking->id, batchId: $batch->id, eventKey: $eventKey, context: $context ?: null, now: $now);

        try {
            DB::transaction(fn () => CheckoutNotification::query()->insertOrIgnore([$row]));
            $this->dispatchPending($booking->id, $batch->id);
        } catch (Throwable $e) {
            Log::error('NotificationOutbox: recording failed.', ['kind' => $kind, 'booking_id' => $booking->id, 'batch_id' => $batch->id, 'exception' => class_basename($e)]);
        }
    }

    private function outboxRow(string $kind, string $email, ?int $bookingId = null, ?int $batchId = null, ?int $paymentExceptionId = null, ?string $eventKey = null, ?array $context = null, $now = null): array
    {
        $now ??= now();

        return [
            'checkout_intent_id' => null,
            'booking_id' => $bookingId,
            'batch_id' => $batchId,
            'payment_exception_id' => $paymentExceptionId,
            'kind' => $kind,
            'event_key' => $eventKey,
            'context' => $context !== null ? json_encode($context) : null,
            'recipient' => Crypt::encryptString($email),
            'recipient_hash' => hash('sha256', strtolower(trim($email))),
            'status' => CheckoutNotification::PENDING,
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function dispatchPending(?int $bookingId = null, ?int $batchId = null, ?int $paymentExceptionId = null): void
    {
        $query = CheckoutNotification::query();
        if ($bookingId) {
            $query->where('booking_id', $bookingId);
        }
        if ($batchId) {
            $query->where('batch_id', $batchId);
        }
        if ($paymentExceptionId) {
            $query->where('payment_exception_id', $paymentExceptionId);
        }
        $this->notifier->dispatchDue($query);
    }
}
