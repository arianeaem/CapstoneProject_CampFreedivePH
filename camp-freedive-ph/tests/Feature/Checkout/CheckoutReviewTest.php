<?php

namespace Tests\Feature\Checkout;

use App\Mail\BookingConfirmedMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Services\Checkout\CheckoutReconciler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\TestCase;

/**
 * Batch 2 completion: owner/admin checkout review. Read-only evidence plus one action (a fresh PayMongo re-check
 * through the normal processor). No refund, money movement or manual booking. Coaches are refused server-side.
 */
class CheckoutReviewTest extends TestCase
{
    use BuildsCheckoutIntents;
    use FakesPayMongo;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        $this->setUpCheckoutWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** One checkout in each state; returns them by name. */
    private function cases(): array
    {
        $paidFull = $this->checkoutIntent(60, ['status' => CheckoutIntent::STATUS_PAID_UNALLOCATED, 'unallocated_reason' => 'capacity_unavailable',
            'paymongo_payment_id' => 'pay_test_bc_full', 'paid_amount' => 4000, 'paid_currency' => 'PHP', 'payment_method' => 'gcash']);
        $review = $this->checkoutIntent(60, ['status' => CheckoutIntent::STATUS_PAYMENT_REVIEW, 'failure_reason' => 'amount_mismatch']);
        $unresolved = $this->checkoutIntent(80 * 60, ['resolution_status' => CheckoutReconciler::AUTHORIZATION_UNRESOLVED, 'resolution_note' => 'Synthetic unresolved note.']);
        $exhausted = $this->checkoutIntent(91 * 24 * 60, ['resolution_status' => CheckoutReconciler::NEEDS_STAFF_CHECK, 'resolution_note' => 'Synthetic exhausted note.']);
        $plainPending = $this->checkoutIntent(30);
        $finalized = $this->checkoutIntent(60, ['status' => CheckoutIntent::STATUS_FINALIZED, 'resolution_status' => CheckoutReconciler::AUTHORIZATION_UNRESOLVED]);
        $expired = $this->checkoutIntent(60, ['status' => CheckoutIntent::STATUS_EXPIRED]);

        return compact('paidFull', 'review', 'unresolved', 'exhausted', 'plainPending', 'finalized', 'expired');
    }

    private function listed($response): array
    {
        return collect($response->viewData('intents')->items())->pluck('reference')->sort()->values()->all();
    }

    public function test_owner_and_admin_see_every_open_case_and_nothing_resolved(): void
    {
        $c = $this->cases();
        $expected = collect([$c['paidFull'], $c['review'], $c['unresolved'], $c['exhausted']])->pluck('reference')->sort()->values()->all();

        foreach (['owner' => '/owner', 'admin' => '/admin'] as $role => $prefix) {
            $response = $this->actingAs($this->staffUser($role))->get("{$prefix}/payments/checkout-review")->assertOk();
            $this->assertSame($expected, $this->listed($response), "{$role} list");
            $response->assertSee('Paid, date full')->assertSee('Payment review')->assertDontSee($c['finalized']->reference)->assertDontSee($c['expired']->reference);
        }
    }

    public function test_queue_filters_select_one_state(): void
    {
        $c = $this->cases();
        $admin = $this->staffUser('admin');

        $only = fn (string $queue) => $this->listed($this->actingAs($admin)->get("/admin/payments/checkout-review?queue={$queue}")->assertOk());

        $this->assertSame([$c['paidFull']->reference], $only('paid_unallocated'));
        $this->assertSame([$c['review']->reference], $only('payment_review'));
        $this->assertSame([$c['unresolved']->reference], $only('authorization_unresolved'), 'A flag on a finalized checkout is not an open case.');
        $this->assertSame([$c['exhausted']->reference], $only('needs_staff_check'));
        $this->actingAs($admin)->get('/admin/payments/checkout-review?queue=refunds')->assertSessionHasErrors('queue');
    }

    public function test_detail_shows_verified_evidence_and_manual_decision_without_actions_that_move_money(): void
    {
        $c = $this->cases();
        $response = $this->actingAs($this->staffUser('owner'))->get("/owner/payments/checkout-review/{$c['paidFull']->id}")->assertOk();

        $response->assertSee($c['paidFull']->reference)->assertSee('pay_test_bc_full')->assertSee('PHP 4,000.00')->assertSee('gcash')
            ->assertSee('Manual decision needed')->assertSee(self::CONTACT_EMAIL)
            ->assertDontSee('Re-check payment with PayMongo')->assertDontSee('refund/approve', false)->assertDontSee('Issue refund');
    }

    public function test_coach_and_guest_are_refused(): void
    {
        $c = $this->cases();
        $coach = $this->staffUser('coach');
        Http::fake();

        foreach (['/admin', '/owner'] as $prefix) {
            $this->actingAs($coach)->get("{$prefix}/payments/checkout-review")->assertForbidden();
            $this->actingAs($coach)->get("{$prefix}/payments/checkout-review/{$c['review']->id}")->assertForbidden();
            $this->actingAs($coach)->post("{$prefix}/payments/checkout-review/{$c['review']->id}/recheck")->assertForbidden();
        }
        auth()->logout();
        $this->get('/admin/payments/checkout-review')->assertRedirect(route('login'));

        Http::assertNothingSent();
        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $c['review']->fresh()->status);
    }

    public function test_recheck_of_a_reviewed_payment_uses_the_normal_path_and_confirms_once(): void
    {
        $admin = $this->staffUser('admin');
        $intent = $this->checkoutIntent(60, ['status' => CheckoutIntent::STATUS_PAYMENT_REVIEW, 'failure_reason' => 'session_not_found']);
        $this->fakeSessionRetrieval($intent->paymongo_checkout_session_id, $this->paidSessionBody($intent));

        $this->actingAs($admin)->from("/admin/payments/checkout-review/{$intent->id}")
            ->post("/admin/payments/checkout-review/{$intent->id}/recheck")
            ->assertRedirect("/admin/payments/checkout-review/{$intent->id}")->assertSessionHas('success');
        $this->actingAs($admin)->post("/admin/payments/checkout-review/{$intent->id}/recheck")->assertSessionHas('error'); // now decided

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame(1, Booking::count());
        Mail::assertNothingSent(); // queued, not sent inside the staff request
        $this->deliverQueuedCheckoutNotifications();
        Mail::assertSent(BookingConfirmedMail::class, 1);
        $this->assertSame(1, AuditLog::where('action', 'CHECKOUT_STAFF_RECHECK')->where('user_id', $admin->id)->count());
        $this->assertSame(0, DB::table('refund_requests')->count());
    }

    public function test_recheck_never_touches_a_decided_paid_case_and_unpaid_stays_unchanged(): void
    {
        $c = $this->cases();
        $owner = $this->staffUser('owner');
        $this->fakeSessionRetrieval($c['unresolved']->paymongo_checkout_session_id, $this->sessionBody($c['unresolved'], []));

        $this->actingAs($owner)->post("/owner/payments/checkout-review/{$c['paidFull']->id}/recheck")->assertSessionHas('error');
        $this->actingAs($owner)->post("/owner/payments/checkout-review/{$c['unresolved']->id}/recheck")->assertSessionHas('success');

        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, $c['paidFull']->fresh()->status);
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $c['unresolved']->fresh()->status);
        Http::assertSentCount(1); // only the unresolved checkout was read; never an expire request
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/expire'));
        Mail::assertNothingSent();
        $this->assertSame(0, Booking::count());
    }
}
