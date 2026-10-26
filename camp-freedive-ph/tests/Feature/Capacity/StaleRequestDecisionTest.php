<?php

namespace Tests\Feature\Capacity;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRefund;
use App\Models\CancellationRequest;
use App\Models\Payment;
use App\Models\RescheduleRequest;
use App\Services\BatchManagementService;
use App\Services\SlotReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II A hardening (pre-commit verification D1-D4): a guest request decided after the booking changed (e.g. the
 * camp cancelled its batch) is refused without changing anything; a details-only edit never moves a booking out of its
 * (historical) batch. PayMongo and mail are faked.
 */
class StaleRequestDecisionTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;
    use RefundFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        Mail::fake();
    }

    private function batch(string $date, string $status = 'confirmed'): Batch
    {
        return Batch::create(['batch_code' => 'B-' . Str::upper(Str::random(6)), 'name' => "Batch {$date}", 'start_date' => $date,
            'end_date' => Carbon::parse($date)->addDay()->toDateString(), 'status' => $status, 'lifecycle_status' => $status]);
    }

    private function onDate(string $date, string $status, ?int $batchId, int $pax = 2): Booking
    {
        $booking = $this->booking(['status' => $status, 'start_date' => $date, 'end_date' => Carbon::parse($date)->addDay()->toDateString(), 'batch_id' => $batchId]);
        for ($i = 0; $i < $pax; $i++) {
            BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Guest ' . chr(65 + $i), 'age' => 30, 'price_per_person' => 4250]);
        }

        return $booking->fresh();
    }

    private function rescheduleRequest(Booking $booking, string $to): RescheduleRequest
    {
        return RescheduleRequest::create(['booking_id' => $booking->id, 'current_start_date' => $booking->start_date, 'current_end_date' => $booking->end_date,
            'requested_start_date' => $to, 'requested_end_date' => Carbon::parse($to)->addDay()->toDateString(), 'reason' => 'test', 'status' => 'pending']);
    }

    /** A paid booking with a pending guest request; then the camp cancels its batch (the request stays pending). */
    private function campCancelledWithPendingRequest(string $type): array
    {
        $date = now()->addDays(25)->toDateString();
        $batch = $this->batch($date);
        $booking = $this->onDate($date, $type === 'reschedule' ? 'reschedule_requested' : 'cancellation_requested', $batch->id);
        $this->receipt($booking, 2000);
        $request = $type === 'reschedule'
            ? $this->rescheduleRequest($booking, now()->addDays(60)->toDateString())
            : CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 0, 'status' => 'pending', 'policy_version' => $booking->refund_policy_version]);
        app(BatchManagementService::class)->updateStatus($batch, 'cancelled_by_camp', $this->staff(), 'Typhoon signal', ['reason_category' => 'weather_pre_dive', 'activity_started' => 'no']);

        return [$booking->fresh(), $request, $batch];
    }

    /** A refused decision tells the guest nothing (the camp-cancellation email from the setup is expected). */
    private function assertNoDecisionMail(): void
    {
        foreach ([\App\Mail\RescheduleApprovedMail::class, \App\Mail\RescheduleRejectedMail::class, \App\Mail\CancellationApprovedMail::class, \App\Mail\CancellationRejectedMail::class] as $mailable) {
            Mail::assertNotSent($mailable);
            Mail::assertNotQueued($mailable);
        }
    }

    /** Everything a stale decision must leave alone. */
    private function snapshot(Booking $booking, $request): array
    {
        $fresh = $booking->fresh();

        return [
            'booking' => [$fresh->status, $fresh->start_date->toDateString(), $fresh->batch_id],
            'request' => $request->fresh()->status,
            'cases' => BookingRefund::where('booking_id', $booking->id)->orderBy('id')->get(['id', 'status', 'reason_category', 'source'])->toArray(),
            'payments' => Payment::where('booking_id', $booking->id)->orderBy('id')->get(['id', 'status', 'amount', 'review_reason'])->toArray(),
            'places' => [app(SlotReservationService::class)->getConfirmedPaxCount($fresh->start_date->toDateString()),
                app(SlotReservationService::class)->getConfirmedPaxCount(now()->addDays(60)->toDateString())],
            'batches' => Batch::count(),
        ];
    }

    // ================================================================= D1-D3: stale decisions after a camp cancellation

    public function test_D1_approving_a_reschedule_after_a_camp_cancellation_is_refused_and_changes_nothing(): void
    {
        [$booking, $request] = $this->campCancelledWithPendingRequest('reschedule');
        $before = $this->snapshot($booking, $request);
        $this->assertSame('cancelled_by_camp', $before['booking'][0]);

        $this->actingAs($this->staff())->post("/admin/bookings/requests/reschedule/{$request->id}/approve", ['admin_notes' => 'ok'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'out of date'));

        $this->assertSame($before, $this->snapshot($booking, $request));
        $this->assertNoDecisionMail();
    }

    public function test_D2_rejecting_a_reschedule_after_a_camp_cancellation_is_refused_and_changes_nothing(): void
    {
        [$booking, $request] = $this->campCancelledWithPendingRequest('reschedule');
        $before = $this->snapshot($booking, $request);

        $this->actingAs($this->staff())->post("/admin/bookings/requests/reschedule/{$request->id}/reject", ['admin_notes' => 'no'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'out of date'));

        $this->assertSame($before, $this->snapshot($booking, $request));
        $this->assertNoDecisionMail();
    }

    public function test_D2_rejecting_a_cancellation_after_a_camp_cancellation_is_refused_and_changes_nothing(): void
    {
        [$booking, $request] = $this->campCancelledWithPendingRequest('cancellation');
        $before = $this->snapshot($booking, $request);

        $this->actingAs($this->staff('owner'))->post("/owner/bookings/requests/cancellation/{$request->id}/reject", ['admin_notes' => 'Guest withdrew the request by phone.'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'out of date'));

        $this->assertSame($before, $this->snapshot($booking, $request));
        $this->assertNoDecisionMail();
    }

    public function test_D3_approving_a_cancellation_after_a_camp_cancellation_never_relabels_it(): void
    {
        [$booking, $request] = $this->campCancelledWithPendingRequest('cancellation');
        $before = $this->snapshot($booking, $request);
        $this->assertSame([['status' => BookingRefund::AWAITING_GUEST_CHOICE, 'reason' => 'weather_pre_dive', 'source' => 'camp_cancellation']],
            array_map(fn ($c) => ['status' => $c['status'], 'reason' => $c['reason_category'], 'source' => $c['source']], $before['cases']));

        foreach (['policy_refund', 'forfeit'] as $action) {
            $this->actingAs($this->staff())->post("/admin/bookings/requests/cancellation/{$request->id}/approve", ['action_type' => $action])
                ->assertSessionHas('error', fn ($m) => str_contains($m, 'out of date'));
        }

        $this->assertSame($before, $this->snapshot($booking, $request), 'still a camp cancellation with its weather refund case');
        $this->assertNoDecisionMail();
    }

    public function test_D1_a_reschedule_is_refused_while_a_refund_case_is_open_even_in_the_requested_state(): void
    {
        $date = now()->addDays(25)->toDateString();
        $booking = $this->onDate($date, 'reschedule_requested', $this->batch($date)->id);
        $this->receipt($booking, 2000);
        $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL);
        $booking->update(['status' => 'reschedule_requested']); // e.g. an older case still open
        $request = $this->rescheduleRequest($booking, now()->addDays(60)->toDateString());
        $before = $this->snapshot($booking, $request);

        $this->actingAs($this->staff())->post("/admin/bookings/requests/reschedule/{$request->id}/approve", ['admin_notes' => 'ok'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'refund case'));

        $this->assertSame($before, $this->snapshot($booking, $request));
    }

    // ================================================================= legitimate decisions still work

    public function test_valid_reschedule_approval_moves_dates_and_batch_and_valid_rejections_restore_confirmed(): void
    {
        $date = now()->addDays(25)->toDateString();
        $to = now()->addDays(60)->toDateString();
        $origin = $this->batch($date);
        $moving = $this->onDate($date, 'reschedule_requested', $origin->id);
        $approve = $this->rescheduleRequest($moving, $to);
        $staying = $this->onDate($date, 'reschedule_requested', $origin->id);
        $reject = $this->rescheduleRequest($staying, $to);
        $cancelling = $this->onDate($date, 'cancellation_requested', $origin->id);
        $this->receipt($cancelling, 2000);
        $keep = CancellationRequest::create(['booking_id' => $cancelling->id, 'calculated_refund_amount' => 0, 'status' => 'pending', 'policy_version' => $cancelling->refund_policy_version]);
        $this->actingAs($this->staff());

        $this->post("/admin/bookings/requests/reschedule/{$approve->id}/approve", ['admin_notes' => 'ok'])->assertSessionHas('success');
        $this->post("/admin/bookings/requests/reschedule/{$reject->id}/reject", ['admin_notes' => 'Date is full'])->assertSessionHasNoErrors();
        $this->post("/admin/bookings/requests/cancellation/{$keep->id}/reject", ['admin_notes' => 'Guest changed their mind'])->assertSessionHasNoErrors();

        $moved = $moving->fresh();
        $this->assertSame(['confirmed', $to, $to], [$moved->status, $moved->start_date->toDateString(), $moved->batch->start_date->toDateString()]);
        $this->assertSame(['approved', 'rejected', 'rejected'], [$approve->fresh()->status, $reject->fresh()->status, $keep->fresh()->status]);
        $this->assertSame(['confirmed', $origin->id, 'confirmed'], [$staying->fresh()->status, $staying->fresh()->batch_id, $cancelling->fresh()->status]);
    }

    public function test_historical_promise_guard_still_applies_to_a_current_cancellation_request(): void
    {
        $date = now()->addDays(25)->toDateString();
        $booking = $this->booking(['status' => 'cancellation_requested', 'start_date' => $date, 'end_date' => Carbon::parse($date)->addDay()->toDateString()], policyVersion: null);
        $this->receipt($booking, 2000);
        $request = CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 2000, 'status' => 'pending', 'policy_version' => null]);

        $this->actingAs($this->staff())->post("/admin/bookings/requests/cancellation/{$request->id}/reject", ['admin_notes' => 'Outside the window now'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Only the owner'));
        $this->post("/admin/bookings/requests/cancellation/{$request->id}/approve", ['action_type' => 'forfeit'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'earlier published terms'));

        $this->assertSame(['cancellation_requested', 'pending'], [$booking->fresh()->status, $request->fresh()->status]);
        $this->assertEquals(2000, $request->fresh()->calculated_refund_amount);
    }

    // ================================================================= D4: details-only edits keep the batch

    private function detailsForm(Booking $booking, string $name): array
    {
        return ['start_date' => $booking->start_date->toDateString(), 'end_date' => $booking->end_date->toDateString(), 'contact_name' => $name,
            'contact_email' => $booking->contact_email, 'contact_phone' => '09170000001', 'edit_reason' => 'Fix contact name',
            'participants' => $booking->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'age' => 30])->all()];
    }

    public function test_D4_details_only_edit_keeps_a_completed_booking_in_its_historical_batch(): void
    {
        $date = now()->subDays(10)->toDateString();
        $completed = $this->batch($date, 'completed');
        $booking = $this->onDate($date, 'completed', $completed->id);
        $batches = Batch::count();

        $this->actingAs($this->staff())->put("/admin/bookings/{$booking->id}", $this->detailsForm($booking, 'Renamed Guest'))->assertSessionHas('success');

        $this->assertSame(['Renamed Guest', $completed->id, 'completed'], [$booking->fresh()->contact_name, $booking->fresh()->batch_id, $booking->fresh()->status]);
        $this->assertSame($batches, Batch::count(), 'no batch created for a past date');
    }

    public function test_D4_details_only_edit_keeps_a_camp_cancelled_booking_in_its_cancelled_batch(): void
    {
        $date = now()->addDays(20)->toDateString();
        $cancelledBatch = $this->batch($date, 'cancelled_by_camp');
        $booking = $this->onDate($date, 'cancelled_by_camp', $cancelledBatch->id);
        $batches = Batch::count();

        $this->actingAs($this->staff())->put("/admin/bookings/{$booking->id}", $this->detailsForm($booking, 'Renamed Guest'))->assertSessionHas('success');

        $this->assertSame(['Renamed Guest', $cancelledBatch->id, 'cancelled_by_camp'], [$booking->fresh()->contact_name, $booking->fresh()->batch_id, $booking->fresh()->status]);
        $this->assertSame($batches, Batch::count());
    }

    public function test_D4_a_real_date_change_still_aligns_the_batch(): void
    {
        $date = now()->addDays(20)->toDateString();
        $to = now()->addDays(50)->toDateString();
        $booking = $this->onDate($date, 'confirmed', $this->batch($date)->id);
        $form = array_merge($this->detailsForm($booking, 'Synthetic Guest'), ['start_date' => $to, 'end_date' => Carbon::parse($to)->addDay()->toDateString()]);

        $this->actingAs($this->staff())->put("/admin/bookings/{$booking->id}", $form)->assertSessionHas('success');

        $this->assertSame([$to, $to], [$booking->fresh()->start_date->toDateString(), $booking->fresh()->batch->start_date->toDateString()]);
    }
}
