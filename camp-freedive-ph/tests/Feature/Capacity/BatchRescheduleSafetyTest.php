<?php

namespace Tests\Feature\Capacity;

use App\Mail\BatchRescheduledMail;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\BookingStatusLog;
use App\Models\CancellationRequest;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentExceptionEvent;
use App\Models\RefundAttempt;
use App\Models\RescheduleRequest;
use App\Models\SystemSetting;
use App\Services\BatchManagementService;
use App\Services\Booking\BookingRequestService;
use App\Services\SlotReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II A follow-up: marking a batch "rescheduled" only reschedules bookings that already hold a place. Unpaid
 * bookings are not given one, camp-cancelled bookings are not reactivated (refund cases untouched), and paid bookings
 * without a place (held or not) are left for staff, as are bookings with a pending guest request (the request stays
 * actionable). Skips are logged on the booking and in the audit log; only rescheduled bookings get the reschedule
 * email, once.
 */
class BatchRescheduleSafetyTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;
    use ReceiptFixtures;
    use RefundFixtures;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        Mail::fake();
        SystemSetting::create(['group' => 'camp_operations', 'key' => 'camp_operations.max_batch_capacity', 'value' => '6', 'type' => 'integer']);
        $this->date = now()->addDays(30)->toDateString();
    }

    private function batch(): Batch
    {
        return Batch::create(['batch_code' => 'B-' . Str::upper(Str::random(6)), 'name' => 'Reschedule batch', 'start_date' => $this->date,
            'end_date' => Carbon::parse($this->date)->addDay()->toDateString(), 'status' => 'confirmed', 'lifecycle_status' => 'confirmed']);
    }

    private function reschedule(Batch $batch, ?string $note = 'Boat maintenance'): void
    {
        app(BatchManagementService::class)->updateStatus($batch->fresh(), 'rescheduled', $this->staff('owner'), $note);
    }

    private function confirmedPax(): int
    {
        return app(SlotReservationService::class)->getConfirmedPaxCount($this->date);
    }

    /** One of each kind on the same batch: going (2), unpaid (1), camp-cancelled with an open refund case (1). */
    private function mixedBatch(): array
    {
        $batch = $this->batch();
        $going = $this->paxBooking(2, 'confirmed', $this->date, $batch->id);
        $this->receipt($going, 2000);
        $unpaid = $this->unpaidBooking(1, $this->date, $batch->id);
        $cancelled = $this->paxBooking(1, 'cancelled_by_camp', $this->date, $batch->id);
        $this->receipt($cancelled, 2000);
        $case = $this->campCase($cancelled, BookingRefund::REASON_CAMP_OPERATIONAL);

        return [$batch, $going, $unpaid, $cancelled, $case];
    }

    public function test_only_bookings_holding_a_place_are_rescheduled_and_emailed(): void
    {
        [$batch, $going, $unpaid, $cancelled, $case] = $this->mixedBatch();
        $caseBefore = $case->fresh()->toArray();
        $placesBefore = $this->confirmedPax();

        $this->reschedule($batch);

        $this->assertSame(['rescheduled', 'pending_downpayment', 'cancelled_by_camp'], [$going->fresh()->status, $unpaid->fresh()->status, $cancelled->fresh()->status]);
        $this->assertSame($placesBefore, $this->confirmedPax(), 'no new place taken');
        $this->assertLessThanOrEqual(6, $this->confirmedPax());
        $this->assertSame($caseBefore, $case->fresh()->toArray(), 'refund case untouched');
        $this->assertSame(0, RefundAttempt::count());
        $this->assertSame(['pending'], Payment::where('booking_id', $unpaid->id)->pluck('status')->all(), 'unpaid booking keeps its payment state');
        foreach ([$unpaid, $cancelled] as $kept) {
            $this->assertSame([$this->date, $batch->id], [$kept->fresh()->start_date->toDateString(), $kept->fresh()->batch_id], 'dates and batch unchanged');
        }

        Mail::assertSent(BatchRescheduledMail::class, 1);
        Mail::assertSent(BatchRescheduledMail::class, fn ($mail) => $mail->hasTo($going->contact_email));
        $this->assertSame([$going->id], NotificationLog::pluck('booking_id')->map(fn ($id) => (int) $id)->all(), 'no misleading notification for skipped bookings');
    }

    public function test_skips_are_visible_on_each_booking_and_in_the_audit_log(): void
    {
        [$batch, , $unpaid, $cancelled] = $this->mixedBatch();

        $this->reschedule($batch);

        foreach (['pending downpayment' => $unpaid, 'cancelled by camp' => $cancelled] as $label => $kept) {
            $log = BookingStatusLog::where('booking_id', $kept->id)->latest('id')->first();
            $this->assertSame([$kept->status, $kept->status], [$log->old_status, $log->new_status]);
            $this->assertStringContainsString("does not hold a place ({$label})", $log->note);
            $this->assertStringContainsString('No reschedule email was sent', $log->note);
        }
        $audit = AuditLog::where('action', 'BATCH_STATUS_UPDATED')->latest('id')->value('description');
        $this->assertStringContainsString('Cascaded to 1 bookings', $audit);
        $this->assertStringContainsString('Not rescheduled (left unchanged for staff follow-up): 1 cancelled by camp, 1 pending downpayment.', $audit);
    }

    public function test_paid_bookings_without_a_place_are_never_rescheduled_held_or_not(): void
    {
        $batch = $this->batch();
        $held = $this->unpaidBooking(2, $this->date, $batch->id);
        $this->paxBooking(5, 'confirmed', $this->date, $batch->id);
        $this->payByWebhook($held, 'pay_resched_held')->assertOk();
        $this->assertSame('paid_unallocated', $held->fresh()->status);
        $unflagged = $this->paxBooking(1, 'paid_unallocated', $this->date, $batch->id); // e.g. from before review flags
        $placesBefore = $this->confirmedPax();

        $this->reschedule($batch);

        $this->assertSame(['paid_unallocated', 'paid_unallocated'], [$held->fresh()->status, $unflagged->fresh()->status]);
        $exception = PaymentException::where('booking_id', $held->id)->sole();
        $this->assertSame('open', $exception->status);
        $this->assertSame(1, PaymentExceptionEvent::where('payment_exception_id', $exception->id)->where('type', PaymentExceptionEvent::BATCH_RESCHEDULED)->count());
        $this->assertSame(0, RefundAttempt::count());
        $this->assertSame($placesBefore, $this->confirmedPax());
        $this->assertStringContainsString('2 paid unallocated', AuditLog::where('action', 'BATCH_STATUS_UPDATED')->latest('id')->value('description'));
    }

    public function test_repeating_the_reschedule_is_idempotent_and_never_reactivates_anything(): void
    {
        [$batch, $going, $unpaid, $cancelled] = $this->mixedBatch();

        $this->reschedule($batch);
        $this->reschedule($batch, 'Second notice from staff');
        $this->reschedule($batch, null);

        $this->assertSame(['rescheduled', 'pending_downpayment', 'cancelled_by_camp'], [$going->fresh()->status, $unpaid->fresh()->status, $cancelled->fresh()->status]);
        Mail::assertSent(BatchRescheduledMail::class, 1);
        $this->assertSame(1, BookingStatusLog::where('booking_id', $going->id)->where('new_status', 'rescheduled')->count());
    }

    public function test_staff_route_explains_what_was_left_unchanged(): void
    {
        [$batch] = $this->mixedBatch();
        $admin = $this->staff();

        $this->actingAs($admin)->post("/admin/batches/{$batch->id}/status", ['status' => 'rescheduled', 'note' => 'Resort maintenance'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'bookings with a pending guest request were left unchanged'));
    }

    /** A booking on the batch waiting on a guest request, with its pending request row. */
    private function withRequest(Batch $batch, string $kind): array
    {
        $booking = $this->paxBooking(2, $kind === 'reschedule' ? 'reschedule_requested' : 'cancellation_requested', $this->date, $batch->id);
        $this->receipt($booking, 2000);
        $request = $kind === 'reschedule'
            ? RescheduleRequest::create(['booking_id' => $booking->id, 'current_start_date' => $this->date, 'current_end_date' => $booking->end_date,
                'requested_start_date' => now()->addDays(90)->toDateString(), 'requested_end_date' => now()->addDays(91)->toDateString(), 'reason' => 'Synthetic', 'status' => 'pending'])
            : CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 0, 'status' => 'pending', 'policy_version' => $booking->refund_policy_version]);

        return [$booking, $request];
    }

    public function test_bookings_with_pending_guest_requests_are_left_unchanged_and_their_requests_stay_open(): void
    {
        $batch = $this->batch();
        $going = $this->paxBooking(1, 'confirmed', $this->date, $batch->id);
        $this->receipt($going, 2000);
        [$moving, $moveRequest] = $this->withRequest($batch, 'reschedule');
        [$leaving, $cancelRequest] = $this->withRequest($batch, 'cancellation');
        $placesBefore = $this->confirmedPax();

        $this->reschedule($batch);
        $this->reschedule($batch, 'Second notice');

        $this->assertSame(['rescheduled', 'reschedule_requested', 'cancellation_requested'], [$going->fresh()->status, $moving->fresh()->status, $leaving->fresh()->status]);
        $this->assertSame(['pending', 'pending'], [$moveRequest->fresh()->status, $cancelRequest->fresh()->status], 'never approved, rejected or superseded');
        $this->assertSame($placesBefore, $this->confirmedPax());
        $this->assertLessThanOrEqual(6, $this->confirmedPax());
        Mail::assertSent(BatchRescheduledMail::class, 1);
        Mail::assertSent(BatchRescheduledMail::class, fn ($mail) => $mail->hasTo($going->contact_email));
        $this->assertSame(0, NotificationLog::whereIn('booking_id', [$moving->id, $leaving->id])->count());
        $this->assertSame(0, BookingRefund::count(), 'no refund case opened or changed');
        foreach (['reschedule' => $moving, 'cancellation' => $leaving] as $kind => $kept) {
            $this->assertStringContainsString("pending {$kind} request, which staff still decide in Booking Requests",
                BookingStatusLog::where('booking_id', $kept->id)->latest('id')->value('note'));
        }
        $this->assertStringContainsString('1 cancellation requested, 1 reschedule requested', AuditLog::where('action', 'BATCH_STATUS_UPDATED')->orderBy('id')->value('description'));
    }

    public function test_pending_requests_left_by_a_batch_reschedule_remain_actionable_by_staff(): void
    {
        $batch = $this->batch();
        [$approveMove, $approveMoveRequest] = $this->withRequest($batch, 'reschedule');
        [$rejectMove, $rejectMoveRequest] = $this->withRequest($batch, 'reschedule');
        [$approveCancel, $approveCancelRequest] = $this->withRequest($batch, 'cancellation');
        $this->reschedule($batch);
        $staff = $this->staff('owner');
        $requests = app(BookingRequestService::class);

        $requests->approveReschedule($approveMoveRequest, $staff, 'Guest moves');
        $requests->rejectReschedule($rejectMoveRequest, $staff, 'No other date available');
        $requests->approveCancellation($approveCancelRequest->fresh(), $staff, 'policy_refund', null, 'Guest cancels');

        $this->assertSame(['confirmed', now()->addDays(90)->toDateString()], [$approveMove->fresh()->status, $approveMove->fresh()->start_date->toDateString()]);
        $this->assertSame('confirmed', $rejectMove->fresh()->status);
        $this->assertSame('cancelled_by_guest', $approveCancel->fresh()->status);
        $this->assertSame(['approved', 'rejected', 'approved'], [$approveMoveRequest->fresh()->status, $rejectMoveRequest->fresh()->status, $approveCancelRequest->fresh()->status]);
        $this->assertLessThanOrEqual(6, $this->confirmedPax());
    }

    public function test_a_pending_cancellation_left_by_a_batch_reschedule_can_still_be_rejected(): void
    {
        $batch = $this->batch();
        [$booking, $request] = $this->withRequest($batch, 'cancellation');
        $this->reschedule($batch);

        app(BookingRequestService::class)->rejectCancellation($request, $this->staff('owner'), 'Rejected after review with the guest');

        $this->assertSame(['confirmed', 'rejected'], [$booking->fresh()->status, $request->fresh()->status]);
    }

    public function test_a_booking_that_became_unpaid_or_cancelled_after_the_list_was_read_is_rechecked_under_its_lock(): void
    {
        [$batch, $going] = $this->mixedBatch();
        // Simulate a staff cancellation committed between listing and locking: the row re-read decides
        $going->update(['status' => 'cancelled_by_camp']);

        $this->reschedule($batch);

        $this->assertSame('cancelled_by_camp', $going->fresh()->status);
        Mail::assertNotSent(BatchRescheduledMail::class);
    }
}
