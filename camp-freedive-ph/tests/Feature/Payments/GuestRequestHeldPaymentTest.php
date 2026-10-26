<?php

namespace Tests\Feature\Payments;

use App\Http\Controllers\ManageBookingController;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\CancellationRequest;
use App\Models\PaymentException;
use App\Models\PaymentExceptionEvent;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Models\SystemSetting;
use App\Services\BatchManagementService;
use App\Services\Booking\BookingRequestService;
use App\Services\Payment\RefundService;
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
 * Batch 3-II B1 pre-commit fix: guest reschedule/cancellation requests and the staff decisions on them can neither give
 * a place to a paid booking that got none nor confirm a booking around a held payment. Guests with a payment under
 * review are told the team will contact them; nothing changes. Normal requests still work. Dates are beyond the
 * forecast window, so no weather lookup leaves the process.
 */
class GuestRequestHeldPaymentTest extends TestCase
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
        Http::preventStrayRequests();
        Mail::fake();
        SystemSetting::create(['group' => 'camp_operations', 'key' => 'camp_operations.max_batch_capacity', 'value' => '6', 'type' => 'integer']);
        $this->date = now()->addDays(30)->toDateString();
    }

    private function slots(): SlotReservationService
    {
        return app(SlotReservationService::class);
    }

    /** A legacy booking paid after its date filled up: paid_unallocated with its payment exception. */
    private function paidWithoutPlace(?int $batchId = null): Booking
    {
        $booking = $this->unpaidBooking(2, $this->date, $batchId);
        $this->paxBooking(5, 'confirmed', $this->date, $batchId);
        $this->payByWebhook($booking, 'pay_guest_' . $booking->id)->assertOk();
        $this->assertSame('paid_unallocated', $booking->fresh()->status);

        return $booking->fresh();
    }

    /** A confirmed booking whose second payment is held (paid again). */
    private function confirmedWithHeldPayment(): Booking
    {
        $booking = $this->unpaidBooking(2, $this->date);
        $this->payByWebhook($booking, 'pay_first_' . $booking->id)->assertOk();
        $this->payByWebhook($booking, 'pay_again_' . $booking->id)->assertOk();
        $this->assertSame(['confirmed', PaymentException::KIND_PAID_AGAIN], [$booking->fresh()->status, PaymentException::where('booking_id', $booking->id)->value('kind')]);

        return $booking->fresh();
    }

    private function requestReschedule(Booking $booking)
    {
        return $this->post("/manage-booking/{$booking->booking_number}/reschedule", ['pin' => $booking->pin,
            'requested_start_date' => now()->addDays(60)->toDateString(), 'requested_end_date' => now()->addDays(61)->toDateString(), 'reason' => 'Synthetic request']);
    }

    private function requestCancellation(Booking $booking)
    {
        return $this->post("/manage-booking/{$booking->booking_number}/cancel", ['pin' => $booking->pin, 'confirm_cancel_ack' => '1', 'reason' => 'Synthetic request']);
    }

    private function assertRefusedUnchanged($response, Booking $booking, string $status, int $placesTaken): void
    {
        $response->assertSessionHas('error', ManageBookingController::PAYMENT_UNDER_REVIEW_MESSAGE);
        $this->assertSame($status, $booking->fresh()->status);
        $this->assertSame(0, RescheduleRequest::where('booking_id', $booking->id)->count() + CancellationRequest::where('booking_id', $booking->id)->count(), 'no pending request created');
        $this->assertSame($placesTaken, $this->slots()->getConfirmedPaxCount($this->date), 'capacity occupancy unchanged');
        $this->assertSame('open', PaymentException::where('booking_id', $booking->id)->value('status'));
    }

    public function test_a_paid_booking_without_a_place_cannot_submit_a_reschedule_or_cancellation_request(): void
    {
        $booking = $this->paidWithoutPlace();
        $taken = $this->slots()->getConfirmedPaxCount($this->date);
        $this->assertSame(5, $taken);

        $this->assertRefusedUnchanged($this->requestReschedule($booking), $booking, 'paid_unallocated', $taken);
        $this->assertRefusedUnchanged($this->requestCancellation($booking), $booking, 'paid_unallocated', $taken);
        $this->assertSame(0, BookingRefund::count());
        Mail::assertNothingSent();
    }

    public function test_a_confirmed_booking_with_a_held_payment_cannot_submit_requests_either(): void
    {
        $booking = $this->confirmedWithHeldPayment();
        $taken = $this->slots()->getConfirmedPaxCount($this->date);

        $this->assertRefusedUnchanged($this->requestReschedule($booking), $booking, 'confirmed', $taken);
        $this->assertRefusedUnchanged($this->requestCancellation($booking), $booking, 'confirmed', $taken);
    }

    public function test_the_manage_page_explains_the_review_instead_of_offering_request_forms(): void
    {
        $booking = $this->paidWithoutPlace();
        $this->post('/manage-booking/search', ['booking_number' => $booking->booking_number, 'pin' => $booking->pin]);

        $this->get("/manage-booking/{$booking->booking_number}")->assertOk()
            ->assertSee('Payment under review')->assertSee('including another date or a refund')
            ->assertDontSee('Reschedule Booking Date')->assertDontSee('Request Cancellation');
    }

    public function test_eligible_bookings_still_submit_requests_normally(): void
    {
        $moving = $this->paxBooking(2, 'confirmed', $this->date);
        $this->receipt($moving, 2000);
        $leaving = $this->paxBooking(2, 'confirmed', $this->date);
        $this->receipt($leaving, 2000);
        $taken = $this->slots()->getConfirmedPaxCount($this->date);

        $this->requestReschedule($moving)->assertSessionHas('success');
        $this->requestCancellation($leaving)->assertSessionHas('success');

        $this->assertSame(['reschedule_requested', 'cancellation_requested'], [$moving->fresh()->status, $leaving->fresh()->status]);
        $this->assertSame([1, 1], [RescheduleRequest::where('booking_id', $moving->id)->where('status', 'pending')->count(), CancellationRequest::where('booking_id', $leaving->id)->where('status', 'pending')->count()]);
        $this->assertSame($taken, $this->slots()->getConfirmedPaxCount($this->date), 'a request never changes occupancy');
    }

    public function test_rejecting_or_approving_a_request_never_confirms_around_a_payment_held_meanwhile(): void
    {
        $booking = $this->paxBooking(2, 'confirmed', $this->date);
        $this->receipt($booking, 2000);
        $this->requestReschedule($booking)->assertSessionHas('success');
        $request = RescheduleRequest::where('booking_id', $booking->id)->sole();
        // Meanwhile a repeat payment arrives and is held
        $booking->payments()->create(['payment_method' => 'paymongo', 'transaction_id' => 'PAY-' . Str::upper(Str::random(8)), 'amount' => 2000,
            'payment_type' => 'downpayment', 'status' => 'pending', 'paymongo_resource_id' => "cs_{$booking->id}"]);
        $this->payByWebhook($booking, 'pay_meanwhile')->assertOk();
        $this->assertSame(PaymentException::KIND_PAID_AGAIN, PaymentException::where('booking_id', $booking->id)->value('kind'));
        $requests = app(BookingRequestService::class);
        $staff = $this->staff();

        foreach (['reject' => fn () => $requests->rejectReschedule($request, $staff, 'Synthetic'), 'approve' => fn () => $requests->approveReschedule($request, $staff, 'Synthetic')] as $label => $decide) {
            try {
                $decide();
                $this->fail("{$label} must be refused");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('payment exception #', $e->getMessage(), $label);
            }
        }

        $this->assertSame(['reschedule_requested', 'pending', $this->date], [$booking->fresh()->status, $request->fresh()->status, $booking->fresh()->start_date->toDateString()]);
        $this->assertSame('open', PaymentException::where('booking_id', $booking->id)->value('status'));
    }

    public function test_a_stale_request_from_before_the_fix_cannot_confirm_a_paid_booking_without_a_place(): void
    {
        $booking = $this->paidWithoutPlace();
        // As the old code left it: the guest's request moved a paid_unallocated booking into a pending request
        $booking->update(['status' => 'cancellation_requested']);
        $cancellation = CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 0, 'status' => 'pending', 'policy_version' => $booking->refund_policy_version]);
        $refundRequest = RefundRequest::create(['payment_id' => $booking->payments()->where('status', 'paid')->value('id'), 'booking_id' => $booking->id,
            'requested_by' => 'customer', 'requested_at' => now(), 'status' => 'pending']);
        $staff = $this->staff('owner');

        $refusals = [];
        foreach ([
            'reject cancellation' => fn () => app(BookingRequestService::class)->rejectCancellation($cancellation, $staff, 'Synthetic rejection with a reason'),
            'reject legacy refund request' => fn () => app(RefundService::class)->reject($refundRequest, $staff, 'Synthetic rejection with a reason'),
        ] as $label => $decide) {
            try {
                $decide();
            } catch (RuntimeException $e) {
                $refusals[$label] = $e->getMessage();
            }
        }

        $this->assertCount(2, $refusals);
        foreach ($refusals as $label => $message) {
            $this->assertStringContainsString('cannot be returned to confirmed', $message, $label);
        }
        $this->assertSame(['cancellation_requested', 'pending', 'pending'], [$booking->fresh()->status, $cancellation->fresh()->status, $refundRequest->fresh()->status]);
    }

    public function test_a_batch_reschedule_leaves_a_held_paid_booking_without_a_place_unchanged_and_noted(): void
    {
        $batch = Batch::create(['batch_code' => 'B-' . Str::upper(Str::random(6)), 'name' => 'Batch', 'start_date' => $this->date,
            'end_date' => Carbon::parse($this->date)->addDay()->toDateString(), 'status' => 'confirmed', 'lifecycle_status' => 'confirmed']);
        $waiting = $this->paidWithoutPlace($batch->id);
        $going = Booking::where('batch_id', $batch->id)->where('status', 'confirmed')->sole();

        app(BatchManagementService::class)->updateStatus($batch, 'rescheduled', $this->staff('owner'), 'Boat maintenance');

        $this->assertSame(['paid_unallocated', 'rescheduled'], [$waiting->fresh()->status, $going->fresh()->status]);
        $exception = PaymentException::where('booking_id', $waiting->id)->sole();
        $this->assertSame(1, PaymentExceptionEvent::where('payment_exception_id', $exception->id)->where('type', PaymentExceptionEvent::BATCH_RESCHEDULED)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'PAYMENT_EXCEPTION_BATCH_RESCHEDULE']);
        $this->assertSame(5, $this->slots()->getConfirmedPaxCount($this->date), 'no place given to the held booking');
    }
}
