<?php

namespace Tests\Feature\Capacity;

use App\Mail\CancellationRequestedMail;
use App\Mail\RescheduleApprovedMail;
use App\Mail\RescheduleRequestedMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\BookingStatusLog;
use App\Models\CancellationRequest;
use App\Models\Payment;
use App\Models\RescheduleRequest;
use App\Models\SystemSetting;
use App\Services\Booking\BookingRequestService;
use App\Services\Payment\Allocation\InstructorCapacityVerifier;
use App\Services\Payment\Allocation\UnconfirmedInstructorCapacity;
use App\Services\SlotReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * B5: guest requests and staff decisions work on one lifecycle. A guest has at most one pending request (a reschedule
 * and a cancellation never coexist), only on an active booking that has not taken place; staff decide only the guest's
 * newest pending request, as an active owner or admin; a request never moves capacity until it is decided. Dates are
 * beyond the forecast window, so no weather lookup leaves the process; mail is faked.
 */
class GuestRequestCoordinationTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;
    use ReceiptFixtures;
    use RefundFixtures;

    private string $date;
    private string $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        Http::preventStrayRequests();
        Mail::fake();
        SystemSetting::create(['group' => 'camp_operations', 'key' => 'camp_operations.max_batch_capacity', 'value' => '6', 'type' => 'integer']);
        $this->date = now()->addDays(30)->toDateString();
        $this->to = now()->addDays(60)->toDateString();
    }

    private function paid(string $status = 'confirmed', int $pax = 2, ?string $date = null): Booking
    {
        $booking = $this->paxBooking($pax, $status, $date ?? $this->date);
        $this->receipt($booking, 2000);

        return $booking->fresh();
    }

    private function requestReschedule(Booking $booking, ?string $to = null)
    {
        $to ??= $this->to;

        return $this->post("/manage-booking/{$booking->booking_number}/reschedule", ['pin' => $booking->pin,
            'requested_start_date' => $to, 'requested_end_date' => Carbon::parse($to)->addDay()->toDateString(), 'reason' => 'Synthetic request']);
    }

    private function requestCancellation(Booking $booking)
    {
        return $this->post("/manage-booking/{$booking->booking_number}/cancel", ['pin' => $booking->pin, 'confirm_cancel_ack' => '1', 'reason' => 'Synthetic request']);
    }

    private function pending(Booking $booking): array
    {
        return [RescheduleRequest::where('booking_id', $booking->id)->where('status', 'pending')->count(),
            CancellationRequest::where('booking_id', $booking->id)->where('status', 'pending')->count()];
    }

    private function placesOn(string $date): int
    {
        return app(SlotReservationService::class)->getConfirmedPaxCount($date);
    }

    /** An older request left pending (e.g. from before the camp cancelled and later restored the booking). */
    private function staleReschedule(Booking $booking, string $to): RescheduleRequest
    {
        return RescheduleRequest::create(['booking_id' => $booking->id, 'current_start_date' => $booking->start_date, 'current_end_date' => $booking->end_date,
            'requested_start_date' => $to, 'requested_end_date' => Carbon::parse($to)->addDay()->toDateString(), 'reason' => 'old', 'status' => 'pending']);
    }

    private function staleCancellation(Booking $booking): CancellationRequest
    {
        return CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 0, 'status' => 'pending', 'policy_version' => $booking->refund_policy_version]);
    }

    private function batch(string $date, string $status): Batch
    {
        return Batch::create(['batch_code' => 'B-' . Str::upper(Str::random(6)), 'name' => "Batch {$date}", 'start_date' => $date,
            'end_date' => Carbon::parse($date)->addDay()->toDateString(), 'status' => $status, 'lifecycle_status' => $status]);
    }

    // ------------------------------------------------------------ guest side: one request at a time

    public function test_requests_keep_the_original_place_and_duplicates_are_refused(): void
    {
        $moving = $this->paid();
        $leaving = $this->paid();
        $taken = $this->placesOn($this->date);

        $this->requestReschedule($moving)->assertSessionHas('success');
        $this->requestReschedule($moving)->assertSessionHas('error');
        $this->requestCancellation($leaving)->assertSessionHas('success');
        $this->requestCancellation($leaving)->assertSessionHas('error');

        $this->assertSame([[1, 0], [0, 1]], [$this->pending($moving), $this->pending($leaving)]);
        $this->assertSame(['reschedule_requested', 'cancellation_requested'], [$moving->fresh()->status, $leaving->fresh()->status]);
        $this->assertSame($taken, $this->placesOn($this->date), 'a request never releases the original place');
        $this->assertSame(0, $this->placesOn($this->to), 'nor takes one on the requested date');
        Mail::assertSent(RescheduleRequestedMail::class, 1);
        Mail::assertSent(CancellationRequestedMail::class, 1);
    }

    public function test_a_cancellation_cannot_be_sent_while_a_reschedule_is_pending_and_vice_versa(): void
    {
        $moving = $this->paid();
        $leaving = $this->paid();
        $this->requestReschedule($moving)->assertSessionHas('success');
        $this->requestCancellation($leaving)->assertSessionHas('success');

        $this->requestCancellation($moving)->assertSessionHas('error', fn ($m) => str_contains($m, 'pending reschedule request'));
        $this->requestReschedule($leaving)->assertSessionHas('error', fn ($m) => str_contains($m, 'cancellation request is currently under review'));

        $this->assertSame([[1, 0], [0, 1]], [$this->pending($moving), $this->pending($leaving)], 'the earlier request is kept, nothing added');
        $this->assertSame(['reschedule_requested', 'cancellation_requested'], [$moving->fresh()->status, $leaving->fresh()->status]);
        Mail::assertSent(CancellationRequestedMail::class, 1);
        Mail::assertSent(RescheduleRequestedMail::class, 1);
    }

    public function test_completed_no_show_and_cancelled_bookings_take_no_request(): void
    {
        foreach (['completed', 'no_show', 'cancelled_by_camp', 'cancelled_by_guest'] as $status) {
            $booking = $this->paid($status); // future date: only the lifecycle state stops it
            $this->requestReschedule($booking)->assertSessionHas('error');
            $this->requestCancellation($booking)->assertSessionHas('error');
            $this->assertSame([[0, 0], $status], [$this->pending($booking), $booking->fresh()->status], $status);
        }
        Mail::assertNothingSent();
    }

    public function test_a_rescheduled_booking_can_still_send_a_request(): void
    {
        $booking = $this->paid('rescheduled');
        $this->requestReschedule($booking)->assertSessionHas('success');
        $this->assertSame([[1, 0], 'reschedule_requested'], [$this->pending($booking), $booking->fresh()->status]);
    }

    public function test_the_guest_pin_is_still_required(): void
    {
        $booking = $this->paid();
        $this->post("/manage-booking/{$booking->booking_number}/cancel", ['pin' => '0000', 'confirm_cancel_ack' => '1'])->assertNotFound();
        $this->post("/manage-booking/{$booking->booking_number}/reschedule", ['pin' => '0000', 'requested_start_date' => $this->to,
            'requested_end_date' => Carbon::parse($this->to)->addDay()->toDateString()])->assertNotFound();
        $this->assertSame([[0, 0], 'confirmed'], [$this->pending($booking), $booking->fresh()->status]);
    }

    public function test_csrf_is_enforced_on_guest_requests_and_staff_decisions(): void
    {
        $booking = $this->paid('reschedule_requested');
        $request = $this->staleReschedule($booking, $this->to);
        $this->withMiddleware();
        $this->app['env'] = 'production';

        $this->assertSame(419, $this->call('POST', "/manage-booking/{$booking->booking_number}/cancel", ['pin' => $booking->pin, 'confirm_cancel_ack' => '1'])->status());
        $this->actingAs($this->staff('owner'));
        $this->assertSame(419, $this->call('POST', "/owner/bookings/requests/reschedule/{$request->id}/approve")->status());
        $this->assertSame(['pending', 'reschedule_requested'], [$request->fresh()->status, $booking->fresh()->status]);
    }

    // ------------------------------------------------------------ staff side: newest request only, owner/admin only

    public function test_an_older_pending_request_is_never_decided_against_a_newer_one(): void
    {
        $booking = $this->paid();
        $old = $this->staleReschedule($booking, now()->addDays(90)->toDateString());
        $this->requestReschedule($booking)->assertSessionHas('success');
        $new = RescheduleRequest::where('booking_id', $booking->id)->latest('id')->first();
        $this->actingAs($this->staff());

        foreach (['approve', 'reject'] as $action) {
            $this->post("/admin/bookings/requests/reschedule/{$old->id}/{$action}", ['admin_notes' => 'x'])
                ->assertSessionHas("error", fn ($m) => str_contains($m, "newer request"));
        }
        $this->assertSame(['pending', 'reschedule_requested', $this->date], [$old->fresh()->status, $booking->fresh()->status, $booking->fresh()->start_date->toDateString()]);

        $this->post("/admin/bookings/requests/reschedule/{$new->id}/approve", ['admin_notes' => 'ok'])->assertSessionHas('success');
        $this->assertSame(['confirmed', $this->to, 'approved', 'pending'],
            [$booking->fresh()->status, $booking->fresh()->start_date->toDateString(), $new->fresh()->status, $old->fresh()->status]);

        // The older one stays out of date: the booking no longer waits on a reschedule
        $this->post("/admin/bookings/requests/reschedule/{$old->id}/approve")->assertSessionHas('error');
        $this->assertSame($this->to, $booking->fresh()->start_date->toDateString());
    }

    public function test_an_older_pending_cancellation_is_never_decided_against_a_newer_one(): void
    {
        $booking = $this->paid();
        $old = $this->staleCancellation($booking);
        $this->requestCancellation($booking)->assertSessionHas('success');
        $this->actingAs($this->staff());

        $this->post("/admin/bookings/requests/cancellation/{$old->id}/approve", ['action_type' => 'policy_refund'])->assertSessionHas("error", fn ($m) => str_contains($m, "newer request"));
        $this->post("/admin/bookings/requests/cancellation/{$old->id}/reject", ['admin_notes' => 'x'])->assertSessionHas("error", fn ($m) => str_contains($m, "newer request"));
        $this->assertSame(['cancellation_requested', 0], [$booking->fresh()->status, BookingRefund::count()]);
    }

    public function test_a_stale_request_of_the_other_kind_cannot_be_decided(): void
    {
        $booking = $this->paid();
        $stale = $this->staleCancellation($booking);
        $this->requestReschedule($booking)->assertSessionHas('success');
        $this->actingAs($this->staff());

        $this->post("/admin/bookings/requests/cancellation/{$stale->id}/approve", ['action_type' => 'policy_refund'])->assertSessionHas('error');
        $this->assertSame(['reschedule_requested', 'pending', 0], [$booking->fresh()->status, $stale->fresh()->status, BookingRefund::count()]);
    }

    public function test_only_an_active_owner_or_admin_decides(): void
    {
        $booking = $this->paid('reschedule_requested');
        $request = $this->staleReschedule($booking, $this->to);

        $this->actingAs($this->staff('coach'))->post("/admin/bookings/requests/reschedule/{$request->id}/approve")->assertForbidden();
        $this->actingAs($this->staff('coach'))->post("/owner/bookings/requests/reschedule/{$request->id}/approve")->assertForbidden();

        $inactive = $this->staff();
        $inactive->forceFill(['status' => 'inactive'])->save();
        foreach (['approveReschedule' => [$request, $inactive, null], 'rejectReschedule' => [$request, $inactive, null]] as $method => $args) {
            try {
                app(BookingRequestService::class)->{$method}(...$args);
                $this->fail("{$method} accepted an inactive admin");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('active owner or admin', $e->getMessage());
            }
        }
        $coach = $this->staff('coach');
        $this->expectExceptionMessage('active owner or admin');
        try {
            app(BookingRequestService::class)->rejectReschedule($request, $coach, null);
        } finally {
            $this->assertSame(['pending', 'reschedule_requested'], [$request->fresh()->status, $booking->fresh()->status]);
        }
    }

    public function test_owner_and_admin_both_decide(): void
    {
        $a = $this->paid('reschedule_requested');
        $ra = $this->staleReschedule($a, $this->to);
        $b = $this->paid('cancellation_requested');
        $rb = $this->staleCancellation($b);

        $this->actingAs($this->staff('owner'))->post("/owner/bookings/requests/reschedule/{$ra->id}/reject", ['admin_notes' => 'Date closed'])->assertSessionHas('info');
        $this->actingAs($this->staff('admin'))->post("/admin/bookings/requests/cancellation/{$rb->id}/reject", ['admin_notes' => 'Kept'])->assertSessionHas('info');
        $this->assertSame(['confirmed', 'rejected', 'confirmed', 'rejected'], [$a->fresh()->status, $ra->fresh()->status, $b->fresh()->status, $rb->fresh()->status]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'RESCHEDULE_REJECTED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CANCELLATION_REJECTED']);
    }

    public function test_a_retried_or_conflicting_decision_changes_nothing_and_sends_nothing_more(): void
    {
        $booking = $this->paid();
        $this->requestReschedule($booking)->assertSessionHas('success');
        $request = RescheduleRequest::where('booking_id', $booking->id)->sole();
        $this->actingAs($this->staff());

        $this->post("/admin/bookings/requests/reschedule/{$request->id}/approve")->assertSessionHas('success');
        $this->post("/admin/bookings/requests/reschedule/{$request->id}/approve")->assertSessionHas('error');
        $this->post("/admin/bookings/requests/reschedule/{$request->id}/reject", ['admin_notes' => 'late'])->assertSessionHas('error');

        $this->assertSame(['approved', 'confirmed', $this->to], [$request->fresh()->status, $booking->fresh()->status, $booking->fresh()->start_date->toDateString()]);
        $this->assertSame(1, BookingStatusLog::where('booking_id', $booking->id)->where('old_status', 'reschedule_requested')->count());
        Mail::assertSent(RescheduleApprovedMail::class, 1);
        Mail::assertNotSent(\App\Mail\RescheduleRejectedMail::class);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'RESCHEDULE_APPROVED')->count());
    }

    // ------------------------------------------------------------ destination, money and boundaries

    public function test_a_reschedule_into_a_non_operating_date_is_refused_and_nothing_moves(): void
    {
        $this->batch($this->to, 'cancelled_by_camp');
        $booking = $this->paid();
        $this->requestReschedule($booking)->assertSessionHas('success');
        $request = RescheduleRequest::where('booking_id', $booking->id)->sole();
        $taken = $this->placesOn($this->date);

        $this->actingAs($this->staff())->post("/admin/bookings/requests/reschedule/{$request->id}/approve")->assertSessionHas('error');

        $this->assertSame(['pending', 'reschedule_requested', $this->date], [$request->fresh()->status, $booking->fresh()->status, $booking->fresh()->start_date->toDateString()]);
        $this->assertSame([$taken, 0], [$this->placesOn($this->date), $this->placesOn($this->to)]);
        Mail::assertNotSent(RescheduleApprovedMail::class);
    }

    public function test_a_reschedule_into_a_full_date_is_refused_and_the_original_place_is_kept(): void
    {
        $this->paxBooking(6, 'confirmed', $this->to);
        $booking = $this->paid();
        $this->requestReschedule($booking)->assertSessionHas('success');
        $request = RescheduleRequest::where('booking_id', $booking->id)->sole();

        $this->actingAs($this->staff())->post("/admin/bookings/requests/reschedule/{$request->id}/approve")->assertSessionHas('error');
        $this->assertSame(['pending', $this->date, 6], [$request->fresh()->status, $booking->fresh()->start_date->toDateString(), $this->placesOn($this->to)]);
    }

    public function test_an_approved_cancellation_releases_the_place_only_then_keeps_receipts_and_approves_no_refund(): void
    {
        $booking = $this->paid();
        $receipt = Payment::where('booking_id', $booking->id)->sole();
        $this->requestCancellation($booking)->assertSessionHas('success');
        $request = CancellationRequest::where('booking_id', $booking->id)->sole();
        $this->assertSame(2, $this->placesOn($this->date), 'still held while pending');

        $this->actingAs($this->staff())->post("/admin/bookings/requests/cancellation/{$request->id}/approve", ['action_type' => 'policy_refund'])->assertSessionHas('success');

        $this->assertSame(['cancelled_by_guest', 0], [$booking->fresh()->status, $this->placesOn($this->date)]);
        $case = BookingRefund::where('booking_id', $booking->id)->sole();
        $this->assertSame(BookingRefund::AWAITING_STAFF_REVIEW, $case->status, 'a case to review, never an approved refund');
        $this->assertSame(['paid', '2000.00'], [$receipt->fresh()->status, (string) $receipt->fresh()->amount]);
        $this->assertNull($receipt->fresh()->paymongo_refund_id);
        Http::assertNothingSent();
    }

    public function test_b2_instructor_allocation_stays_fail_closed(): void
    {
        $this->assertInstanceOf(UnconfirmedInstructorCapacity::class, app(InstructorCapacityVerifier::class));
    }
}
