<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\RefundAttempt;
use App\Services\Refunds\FinancialTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II B1: a receipt without a booking (paid checkout, no place) renders everywhere staff look at payments,
 * cannot have a balance settled against it, and is reported as received-but-not-applied money, once.
 */
class UnappliedReceiptPagesTest extends TestCase
{
    use BuildsCheckoutIntents;
    use FakesPayMongo;
    use RefreshDatabase;
    use ReceiptFixtures;
    use RefundFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        $this->setUpCheckoutWorld();
    }

    private function unappliedReceipt(): Payment
    {
        $this->fillDate(5);
        $intent = $this->checkoutIntent(30, ['hold_expires_at' => now()->subMinutes(5)]);
        $this->finalizeIntent($intent);

        return Payment::whereNull('booking_id')->sole();
    }

    public function test_payment_pages_render_an_unapplied_receipt_and_balance_settlement_is_refused(): void
    {
        $receipt = $this->unappliedReceipt();
        $admin = $this->staffUser('admin');

        $this->actingAs($admin)->get("/admin/payments/{$receipt->id}")->assertOk()
            ->assertSee('Not applied to a booking')->assertSee($receipt->checkoutIntent->reference)->assertSee('Paid checkout, no place was left')
            ->assertDontSee('Record Balance Settlement');
        $this->actingAs($admin)->get('/admin/payments')->assertOk()->assertSee('No Booking')->assertSee('Where received money stands');
        $this->actingAs($admin)->get('/admin/payments?search=' . $receipt->paymongo_payment_id)->assertOk();

        $this->actingAs($admin)->post("/admin/payments/{$receipt->id}/settle-balance", ['amount' => 500, 'payment_method' => 'cash'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'not applied to any booking'));
        $this->assertSame(1, Payment::count());
    }

    public function test_owner_dashboard_and_exports_include_held_money_without_crashing(): void
    {
        $receipt = $this->unappliedReceipt();
        $owner = $this->staffUser('owner');

        $this->actingAs($owner)->get('/owner')->assertOk()->assertSee('Of which held for a decision');
        foreach (['revenue', 'bookings'] as $type) {
            $this->actingAs($owner)->get("/owner/reports/export?type={$type}&preset=this_month")->assertOk();
        }
        $this->assertSame(1, PaymentException::where('primary_payment_id', $receipt->id)->count());
    }

    public function test_money_position_separates_cash_applied_unapplied_unverified_held_and_refunds_counting_each_receipt_once(): void
    {
        $receipt = $this->unappliedReceipt();                                 // ₱4,000 checkout, no place: unapplied and held
        $booking = $this->paxBooking(1, 'confirmed', self::DATE);
        $legacy = $this->receipt($booking, 2000, 'paid', 'pay_pos_legacy');   // before identities: currency not verified
        $this->receipt($booking, 1000, 'completed', null);                    // cash at camp
        RefundAttempt::create(['payment_id' => $legacy->id, 'operation_id' => (string) Str::uuid(), 'method' => 'paymongo', 'amount_centavos' => 50000,
            'currency' => 'PHP', 'status' => RefundAttempt::SUCCEEDED, 'resolved_at' => now()]);
        RefundAttempt::create(['payment_id' => $legacy->id, 'operation_id' => (string) Str::uuid(), 'method' => 'paymongo', 'amount_centavos' => 30000,
            'currency' => 'PHP', 'status' => RefundAttempt::UNKNOWN]);

        $p = FinancialTotals::position();

        $this->assertSame([7000.0, 3000.0, 4000.0, 1], [$p['cash_received'], $p['applied'], $p['unapplied'], $p['unapplied_count']]);
        $this->assertSame($p['cash_received'], $p['applied'] + $p['unapplied']);
        $this->assertSame([2000.0, 1], [$p['currency_unverified'], $p['currency_unverified_count']], 'the finalizer receipt is verified; the legacy one is not');
        $this->assertSame([4000.0, 1, 4000.0], [$p['held'], $p['held_count'], $p['retained_unresolved']]);
        $this->assertSame([500.0, 300.0], [$p['completed_refunds'], $p['reserved_refunds']]);
        $this->assertSame((float) DB::table('payments')->whereIn('status', Payment::RECEIPT_STATUSES)->sum('amount'), FinancialTotals::paid(),
            'checkout intents are never added on top of their receipt');
        $this->assertSame('4000.00', (string) $receipt->checkoutIntent->paid_amount);
    }
}
