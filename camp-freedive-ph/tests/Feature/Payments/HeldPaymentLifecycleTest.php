<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentExceptionEvent;
use App\Models\RescheduleRequest;
use App\Models\SystemSetting;
use App\Services\BatchManagementService;
use App\Services\Refunds\RefundLedger;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II B1: while a booking has a held payment (unresolved payment exception or review flag), staff status
 * changes cannot decide that money for the guest: no placement, restore, date move, or camp cancellation from
 * paid_unallocated (B2's guest-choice workflow will). Camp and weather cascades leave such a booking unchanged with an
 * auditable event. Both orders of "status change" and "late payment" end safely. Receipts are never deleted with a
 * booking (RESTRICT).
 */
class HeldPaymentLifecycleTest extends TestCase
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

    private function batch(string $date): Batch
    {
        return Batch::create(['batch_code' => 'B-' . Str::upper(Str::random(6)), 'name' => "Batch {$date}", 'start_date' => $date,
            'end_date' => Carbon::parse($date)->addDay()->toDateString(), 'status' => 'confirmed', 'lifecycle_status' => 'confirmed']);
    }

    /** A legacy booking paid after its date filled up: paid_unallocated, with its exception. */
    private function paidWithoutPlace(?int $batchId = null): Booking
    {
        $booking = $this->unpaidBooking(2, $this->date, $batchId);
        $this->paxBooking(5, 'confirmed', $this->date, $batchId);
        $this->payByWebhook($booking, 'pay_held_' . $booking->id)->assertOk();
        $this->assertSame('paid_unallocated', $booking->fresh()->status);

        return $booking->fresh();
    }

    private function editForm(Booking $booking, string $date, string $name = 'Synthetic Guest'): array
    {
        return ['start_date' => $date, 'end_date' => Carbon::parse($date)->addDay()->toDateString(), 'contact_name' => $name, 'contact_email' => $booking->contact_email,
            'contact_phone' => '09170000001', 'edit_reason' => 'Guest asked',
            'participants' => $booking->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'age' => 30])->all()];
    }

    public function test_a_paid_booking_without_a_place_is_not_placed_camp_cancelled_or_moved_by_staff(): void
    {
        $booking = $this->paidWithoutPlace();
        Booking::where('status', 'confirmed')->update(['status' => 'cancelled_by_guest']); // a place opens up meanwhile
        $this->actingAs($this->staff());

        foreach (['confirmed', 'cancelled_by_camp'] as $to) {
            $this->patch("/admin/bookings/{$booking->id}/status", ['status' => $to, 'note' => 'Staff decision'])
                ->assertSessionHas('error', fn ($m) => str_contains($m, 'payment exception #') && str_contains($m, "guest's choice"));
        }
        $this->put("/admin/bookings/{$booking->id}", $this->editForm($booking, now()->addDays(90)->toDateString()))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'cannot be moved to other dates yet'));

        // A details-only edit (same dates) is still allowed
        $this->put("/admin/bookings/{$booking->id}", $this->editForm($booking, $this->date, 'Renamed Guest'))->assertSessionHas('success');

        $fresh = $booking->fresh();
        $this->assertSame(['paid_unallocated', $this->date, 'Renamed Guest'], [$fresh->status, $fresh->start_date->toDateString(), $fresh->contact_name]);
        $this->assertSame(0, BookingRefund::count());
        $this->assertSame('open', PaymentException::sole()->status);
    }

    public function test_a_cancelled_booking_paid_afterwards_cannot_be_restored_around_its_exception(): void
    {
        $booking = $this->unpaidBooking(2, $this->date);
        $booking->update(['status' => 'cancelled_by_guest']);
        $this->payByWebhook($booking, 'pay_held_late')->assertOk();

        $this->actingAs($this->staff())->patch("/admin/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Paid after the booking was cancelled'));

        $this->assertSame('cancelled_by_guest', $booking->fresh()->status);
    }

    public function test_a_confirmed_booking_paid_again_cannot_be_moved_but_can_still_be_cancelled_by_the_camp(): void
    {
        $booking = $this->unpaidBooking(2, $this->date);
        $this->payByWebhook($booking, 'pay_held_first')->assertOk();
        $this->payByWebhook($booking, 'pay_held_again')->assertOk();
        $request = RescheduleRequest::create(['booking_id' => $booking->id, 'current_start_date' => $booking->start_date, 'current_end_date' => $booking->end_date,
            'requested_start_date' => now()->addDays(90)->toDateString(), 'requested_end_date' => now()->addDays(91)->toDateString(), 'reason' => 'test', 'status' => 'pending']);
        $booking->update(['status' => 'reschedule_requested']);
        $this->actingAs($this->staff());

        $this->post("/admin/bookings/requests/reschedule/{$request->id}/approve", ['admin_notes' => 'ok'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'cannot be moved to other dates yet'));
        $this->assertSame(['pending', $this->date], [$request->fresh()->status, $booking->fresh()->start_date->toDateString()]);

        $booking->update(['status' => 'confirmed']);
        $this->patch("/admin/bookings/{$booking->id}/status", ['status' => 'cancelled_by_camp', 'note' => 'Boat engine failure'])->assertSessionHas('success');
        $this->assertSame('cancelled_by_camp', $booking->fresh()->status);
        $position = app(RefundLedger::class)->position($booking);
        $this->assertSame([400000, 200000, 200000], [$position['refundable'], $position['held'], $position['spendable']], 'the repeat payment stays held in the camp case');
    }

    public function test_camp_and_weather_cascades_leave_a_held_paid_unallocated_booking_unchanged_with_an_event(): void
    {
        foreach (['camp', 'weather'] as $i => $cascade) {
            $this->date = now()->addDays(40 + $i * 10)->toDateString();
            $batch = $this->batch($this->date);
            $waiting = $this->paidWithoutPlace($batch->id);
            $going = Booking::where('batch_id', $batch->id)->where('status', 'confirmed')->sole();
            $owner = $this->staff('owner');

            $cascade === 'camp'
                ? app(BatchManagementService::class)->updateStatus($batch, 'cancelled_by_camp', $owner, 'Typhoon signal', ['reason_category' => 'weather_pre_dive', 'activity_started' => 'no'])
                : app(WeatherForecastService::class)->cancelBatchWithRefundsAndNotifications($batch, 'Gale warning', $owner, 'no');

            $this->assertSame(['paid_unallocated', 'cancelled_by_camp'], [$waiting->fresh()->status, $going->fresh()->status], $cascade);
            $exception = PaymentException::where('booking_id', $waiting->id)->sole();
            $event = PaymentExceptionEvent::where('payment_exception_id', $exception->id)->where('type', PaymentExceptionEvent::BATCH_CANCELLED)->sole();
            $this->assertSame([$batch->id, true], [$event->details['batch_id'], $event->details['left_unchanged']]);
            $this->assertSame(0, BookingRefund::where('booking_id', $waiting->id)->count(), 'no refund case for held money');
            $this->assertSame('open', $exception->fresh()->status);
        }
        $this->assertSame(2, AuditLog::where('action', 'PAYMENT_EXCEPTION_CAMP_CANCELLATION')->count());
    }

    public function test_race_order_status_change_first_then_late_payment_never_inherits_the_change(): void
    {
        $booking = $this->unpaidBooking(2, $this->date);
        $this->actingAs($this->staff())->patch("/admin/bookings/{$booking->id}/status", ['status' => 'cancelled_by_camp', 'note' => 'Boat engine failure'])->assertSessionHas('success');

        $this->payByWebhook($booking, 'pay_race_after')->assertOk();

        $this->assertSame('cancelled_by_camp', $booking->fresh()->status);
        $this->assertSame(PaymentException::KIND_PAID_AFTER_CANCELLATION, PaymentException::sole()->kind);
        $this->assertSame(0, \App\Models\CheckoutNotification::count(), 'no confirmation');
    }

    public function test_race_order_late_payment_first_then_status_change_is_refused(): void
    {
        $booking = $this->paidWithoutPlace();

        $this->actingAs($this->staff())->patch("/admin/bookings/{$booking->id}/status", ['status' => 'confirmed'])->assertSessionHas('error');

        $this->assertSame('paid_unallocated', $booking->fresh()->status);
    }

    public function test_a_booking_with_receipts_is_never_deleted_but_the_pending_row_cleanup_still_works(): void
    {
        $paid = $this->paxBooking(1);
        $receipt = $this->receipt($paid, 2000);
        $unpaid = $this->unpaidBooking(1);

        $refused = null;
        try {
            DB::transaction(fn () => $paid->delete());
        } catch (QueryException $e) {
            $refused = $e;
        }
        DB::transaction(function () use ($unpaid) { // the failed-checkout cleanup (BookingController)
            $unpaid->payments()->where('status', 'pending')->delete();
            $unpaid->participants()->delete();
            $unpaid->delete();
        });

        $this->assertNotNull($refused, 'RESTRICT');
        $this->assertNotNull($paid->fresh());
        $this->assertNotNull($receipt->fresh());
        $this->assertNull($unpaid->fresh());
        $this->assertSame(1, Payment::count());
    }
}
