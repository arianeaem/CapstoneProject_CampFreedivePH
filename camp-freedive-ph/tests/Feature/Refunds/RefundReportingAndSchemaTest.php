<?php

namespace Tests\Feature\Refunds;

use App\Models\BookingRefund;
use App\Models\Payment;
use App\Models\RefundAttempt;
use App\Services\Refunds\FinancialTotals;
use App\Services\Refunds\RefundLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-I: one set of money definitions (paid, refunded, retained, collectible) and the additive ledger schema.
 */
class RefundReportingAndSchemaTest extends TestCase
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

    private function attempt(Payment $payment, int $centavos, string $status, string $method = 'paymongo'): RefundAttempt
    {
        return RefundAttempt::create(['payment_id' => $payment->id, 'operation_id' => (string) Str::uuid(), 'method' => $method,
            'amount_centavos' => $centavos, 'status' => $status, 'resolved_at' => now()]);
    }

    // ---------------------------------------------------------------- reporting

    public function test_paid_refunded_and_retained_use_receipts_and_confirmed_refunds_only(): void
    {
        $single = $this->booking();
        $this->receipt($single, 4250, 'paid', 'auto', 'full');                       // single receipt, no refund
        $installments = $this->booking();
        $dp = $this->receipt($installments, 2000);
        $bal = $this->receipt($installments, 2250, 'completed', null, 'balance_settlement');
        $this->attempt($dp, 50000, RefundAttempt::SUCCEEDED);                         // partial, confirmed
        $this->attempt($bal, 225000, RefundAttempt::OFFLINE_CONFIRMED, 'offline');    // full, offline documented
        $this->attempt($dp, 30000, RefundAttempt::PROVIDER_PENDING);                  // not refunded yet
        $this->attempt($dp, 20000, RefundAttempt::UNKNOWN);                           // not refunded yet
        $legacy = $this->booking();
        $this->receipt($legacy, 3000, 'refunded', 'auto', 'downpayment', ['amount_refunded' => 3000]); // old model

        $this->assertEquals(11500.0, FinancialTotals::paid(), 'Refunded payments are still receipts.');
        $this->assertEquals(5750.0, FinancialTotals::refunded(), 'Pending and unknown are not refunded.');

        $position = app(RefundLedger::class)->position($installments);
        $this->assertSame([425000, 275000, 50000, 150000, 100000], [$position['paid'], $position['refunded'], $position['reserved'], $position['retained'], $position['refundable']]);
        $this->assertFalse($position['reconciliation_needed']);

        $this->actingAs($this->staff('owner'))->get('/owner/payments')->assertOk()
            ->assertViewHas('stats', fn ($s) => (float) $s['total_gross'] === 11500.0 && (float) $s['total_refunded'] === 5750.0 && (float) $s['total_retained'] === 5750.0);
    }

    public function test_approved_but_unpaid_refunds_are_not_reported_as_refunded(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        app(\App\Services\Refunds\RefundCaseService::class)->approve($this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL), 200000, $this->staff(), 'ok');

        $this->assertSame(0.0, FinancialTotals::refunded());
        $this->actingAs($this->staff('owner'))->get('/owner')->assertOk()->assertViewHas('financials', fn ($f) => (float) $f['refunds_processed'] === 0.0);
    }

    public function test_inconsistent_old_refund_records_are_flagged_for_reconciliation_not_guessed(): void
    {
        $booking = $this->booking();
        $this->receipt($booking, 2000, 'refunded', 'auto', 'downpayment', ['amount_refunded' => 0]); // "refunded" with no amount

        $position = app(RefundLedger::class)->position($booking);

        $this->assertTrue($position['reconciliation_needed']);
        $this->assertSame(0, $position['refundable'], 'Treated as fully refunded: never refunded again.');
    }

    public function test_cancelled_booking_shows_no_collectible_balance_to_the_guest(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $this->mock(\App\Services\WeatherSafetyService::class, fn ($m) => $m->shouldReceive('isStormSignalActive')->andReturn(false)
            ->shouldReceive('getForecast')->andReturn(['is_bookable' => true, 'risk_level' => 'safe', 'overall_classification' => 'Safe', 'day1' => [], 'day2' => []]));

        $this->withSession(['auth_booking_id' => $booking->id])->get("/manage-booking/{$booking->booking_number}")
            ->assertOk()->assertSee('No balance to pay (booking cancelled)')->assertDontSee('balance due at camp (check-out)');
    }

    public function test_refund_cases_are_staff_only_and_coaches_cannot_view_or_pay_out(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $case = app(\App\Services\Refunds\RefundCaseService::class)->approve($this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL), 200000, $this->staff(), 'ok');

        $this->get("/admin/payments/refund-cases/{$case->id}")->assertRedirect();
        $this->post("/admin/payments/refund-cases/{$case->id}/execute")->assertRedirect();
        $coach = $this->staff('coach');
        $this->actingAs($coach)->get('/admin/payments/refund-cases')->assertForbidden();
        $this->actingAs($coach)->post("/admin/payments/refund-cases/{$case->id}/execute")->assertForbidden();

        $this->assertSame([], $posts);
        $this->assertSame(0, RefundAttempt::count());
        $this->assertSame(BookingRefund::APPROVED, $case->fresh()->status);
    }

    // ------------------------------------------------------------------- schema

    public function test_ledger_schema_is_additive_with_unique_identifiers(): void
    {
        foreach (['booking_refunds', 'refund_attempts', 'paymongo_webhook_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumns('bookings', ['refund_policy_version']));
        $this->assertTrue(Schema::hasColumns('cancellation_requests', ['policy_version']));
        $this->assertTrue(Schema::hasColumns('refund_requests', ['booking_refund_id']));

        $payment = $this->receipt($this->booking(), 2000);
        $op = (string) Str::uuid();
        RefundAttempt::create(['payment_id' => $payment->id, 'operation_id' => $op, 'method' => 'paymongo', 'amount_centavos' => 100, 'status' => 'failed', 'provider_refund_id' => null]);
        RefundAttempt::create(['payment_id' => $payment->id, 'operation_id' => (string) Str::uuid(), 'method' => 'paymongo', 'amount_centavos' => 100, 'status' => 'failed', 'provider_refund_id' => null]);
        $this->assertSame(2, RefundAttempt::whereNull('provider_refund_id')->count(), 'Several attempts may lack a provider id.');

        foreach ([['operation_id' => $op], ['provider_refund_id' => 'ref_dup']] as $i => $duplicate) {
            if ($i === 1) {
                RefundAttempt::create(['payment_id' => $payment->id, 'operation_id' => (string) Str::uuid(), 'method' => 'paymongo', 'amount_centavos' => 100, 'status' => 'succeeded', 'provider_refund_id' => 'ref_dup']);
            }
            try {
                DB::transaction(fn () => RefundAttempt::create(array_merge(['payment_id' => $payment->id, 'operation_id' => (string) Str::uuid(), 'method' => 'paymongo', 'amount_centavos' => 100, 'status' => 'failed'], $duplicate)));
                $this->fail('Duplicate ' . key($duplicate) . ' accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_payment_id_preflight_finds_historical_duplicates_without_a_constraint_blocking_them(): void
    {
        $booking = $this->booking();
        $this->receipt($booking, 2000, 'paid', 'pay_test_dupe');
        $this->receipt($booking, 2000, 'paid', 'pay_test_dupe'); // possible in historical data: no unique constraint yet
        $this->receipt($booking, 2000, 'paid', null);
        $this->receipt($booking, 2000, 'paid', null);

        // The read-only preflight query from the Batch 3-I report
        $duplicates = DB::select('select paymongo_payment_id, count(*) as n from payments where paymongo_payment_id is not null group by paymongo_payment_id having count(*) > 1');

        $this->assertCount(1, $duplicates);
        $this->assertSame('pay_test_dupe', $duplicates[0]->paymongo_payment_id);
    }

    public function test_new_bookings_carry_the_current_policy_version_and_old_rows_stay_unversioned(): void
    {
        $new = \App\Models\Booking::create(['booking_number' => 'CFP-2026-NEWV', 'pin' => '1111', 'class_type' => 'discovery',
            'start_date' => now()->addDays(30), 'end_date' => now()->addDays(31), 'total_amount' => 4250, 'downpayment_amount' => 2000,
            'balance_amount' => 2250, 'contact_name' => 'Synthetic', 'contact_email' => 'v@example.test', 'contact_phone' => '09170000001', 'status' => 'confirmed']);
        $old = $this->booking([], policyVersion: null);

        $this->assertSame(config('refunds.policy_version'), $new->refund_policy_version);
        $this->assertNull($old->refund_policy_version);
    }
}
