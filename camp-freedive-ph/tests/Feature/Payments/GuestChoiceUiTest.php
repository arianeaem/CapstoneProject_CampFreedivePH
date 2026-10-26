<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\PaymentException;
use App\Models\PaymentExceptionChoice;
use App\Models\PaymentExceptionEvent;
use App\Models\PaymentExceptionRefundReview;
use App\Services\Payment\GuestChoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II B2.2: the staff screens for guest choices. Owner and admin only; evidence only in POST bodies, never
 * flashed back, logged or put in URLs; sensitive data refused; every evidence view audited. Synthetic evidence only.
 */
class GuestChoiceUiTest extends TestCase
{
    use BuildsCheckoutIntents;
    use FakesPayMongo;
    use RefreshDatabase;
    use ReceiptFixtures;
    use RefundFixtures;

    private const SUMMARY = 'Synthetic: guest asked for a refund review by reply.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        $this->setUpCheckoutWorld();
    }

    private function exception(): PaymentException
    {
        $this->fillDate(5);
        $intent = $this->checkoutIntent(30, ['hold_expires_at' => now()->subMinutes(5)]);
        $this->finalizeIntent($intent);

        return PaymentException::where('checkout_intent_id', $intent->id)->sole();
    }

    private function form(array $overrides = []): array
    {
        return array_merge(['choice' => 'refund_review', 'evidence_channel' => 'email', 'evidence_at' => now()->subHour()->format('Y-m-d\TH:i'),
            'evidence_reference' => 'synthetic-thread-0009', 'evidence_summary' => self::SUMMARY, 'contact_match_method' => 'email_address',
            'contact_match_outcome' => 'matched'], $overrides);
    }

    public function test_owner_and_admin_record_verify_and_route_through_the_screens(): void
    {
        $exception = $this->exception();
        $admin = $this->staffUser('admin');
        $owner = $this->staffUser('owner');

        $this->actingAs($admin)->post("/admin/payments/exceptions/{$exception->id}/choices", $this->form())
            ->assertRedirect("/admin/payments/exceptions/{$exception->id}")->assertSessionHas('success');
        $choice = PaymentExceptionChoice::sole();
        $this->assertSame([PaymentExceptionChoice::RECORDED, self::SUMMARY], [$choice->status, $choice->evidence_summary]);

        $this->actingAs($admin)->get("/admin/payments/exceptions/{$exception->id}")->assertOk()->assertSee('different owner or admin must verify')->assertDontSee('>Verify<', false);
        $this->actingAs($admin)->post("/admin/payments/exceptions/{$exception->id}/choices/{$choice->id}/verify", ['verification_note' => 'Synthetic: self check attempt.', 'lock_version' => '0'])
            ->assertSessionHas('error');
        $this->assertSame(PaymentExceptionChoice::RECORDED, $choice->fresh()->status, 'never self-verified');

        $this->actingAs($owner)->get("/owner/payments/exceptions/{$exception->id}")->assertOk()->assertSee(self::SUMMARY);
        $this->actingAs($owner)->post("/owner/payments/exceptions/{$exception->id}/choices/{$choice->id}/verify", ['verification_note' => 'Synthetic: confirmed with the booking email.', 'lock_version' => '0'])
            ->assertSessionHas('success');
        $this->actingAs($admin)->post("/admin/payments/exceptions/{$exception->id}/choices/{$choice->id}/refund-review", ['lock_version' => '1'])->assertSessionHas('success');

        $this->assertSame(PaymentExceptionChoice::ACTED_ON, $choice->fresh()->status);
        $this->assertSame(1, PaymentExceptionRefundReview::count());
        $this->assertSame('open', $exception->fresh()->status);
        $this->actingAs($owner)->get("/owner/payments/exceptions/{$exception->id}")->assertOk()->assertSee('In owner refund review')->assertDontSee('Record the guest');
        $this->actingAs($owner)->get('/owner/payments/exceptions/queue')->assertOk()->assertSee('Owner refund review (1)')->assertDontSee(self::SUMMARY);
        $this->actingAs($owner)->get('/owner/payments/exceptions')->assertOk()->assertSee("/owner/payments/exceptions/{$exception->id}", false);
    }

    public function test_coaches_and_guests_cannot_see_or_act(): void
    {
        $exception = $this->exception();
        $choice = app(GuestChoiceService::class)->record($exception, $this->staffUser('admin'), array_merge($this->form(), ['evidence_at' => now()->subHour()->toIso8601String()]));
        $coach = $this->staffUser('coach');

        foreach (["/admin/payments/exceptions/{$exception->id}", '/admin/payments/exceptions/queue', "/coach/payments/exceptions/{$exception->id}"] as $url) {
            $this->assertContains($this->actingAs($coach)->get($url)->status(), [302, 403, 404], $url);
        }
        $this->actingAs($coach)->post("/admin/payments/exceptions/{$exception->id}/choices/{$choice->id}/verify", ['verification_note' => 'Synthetic: coach attempt.', 'lock_version' => '0'])
            ->assertStatus(403);
        auth()->logout();
        $this->get("/admin/payments/exceptions/{$exception->id}")->assertRedirect();
        $this->post("/admin/payments/exceptions/{$exception->id}/choices", $this->form())->assertRedirect();

        $this->assertSame(PaymentExceptionChoice::RECORDED, $choice->fresh()->status);
        $this->assertSame(1, PaymentExceptionChoice::count());
        $this->assertSame(0, PaymentExceptionEvent::where('type', PaymentExceptionEvent::EVIDENCE_VIEWED)->count(), 'no evidence was shown');
    }

    public function test_each_evidence_view_is_audited_without_its_content(): void
    {
        $exception = $this->exception();
        app(GuestChoiceService::class)->record($exception, $this->staffUser('admin'), array_merge($this->form(), ['evidence_at' => now()->subHour()->toIso8601String()]));
        $viewer = $this->staffUser('owner');

        $this->actingAs($viewer)->get("/owner/payments/exceptions/{$exception->id}")->assertOk();
        $this->actingAs($viewer)->get("/owner/payments/exceptions/{$exception->id}")->assertOk();

        $views = PaymentExceptionEvent::where('type', PaymentExceptionEvent::EVIDENCE_VIEWED)->get();
        $this->assertSame([$viewer->id, $viewer->id], $views->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(2, AuditLog::where('action', 'PAYMENT_EXCEPTION_EVIDENCE_VIEWED')->count());
        $this->assertStringNotContainsString('Synthetic', json_encode($views->pluck('details')) . AuditLog::pluck('description')->implode(' '));
    }

    public function test_sensitive_text_is_refused_and_never_flashed_back_or_logged(): void
    {
        $exception = $this->exception();
        $admin = $this->staffUser('admin');
        Log::spy();

        $samples = [
            'Synthetic: guest wrote from someone.else@example.test asking for a refund.',
            'Synthetic: guest said call 0917 123 4567 to confirm the refund.',
            'Synthetic: guest said call +63 917 123 4567 to confirm the refund.',
            'Synthetic: guest sent card 4111 1111 1111 1111 for the refund.',
            'Synthetic: guest shared their PIN 4821 for the manage page.',
            'Synthetic: guest gave password Hunter2Secret for access.',
        ];
        foreach ($samples as $summary) {
            $response = $this->actingAs($admin)->post("/admin/payments/exceptions/{$exception->id}/choices", $this->form(['evidence_summary' => $summary]));
            $response->assertRedirect("/admin/payments/exceptions/{$exception->id}")->assertSessionHas('error');
            $this->assertStringNotContainsString($summary, (string) session('error'));
            $this->assertNull(session()->getOldInput('evidence_summary'), 'free text is never flashed back');
            $this->assertStringNotContainsString('?', (string) $response->headers->get('Location'), 'no evidence in the URL');
        }
        $this->actingAs($admin)->post("/admin/payments/exceptions/{$exception->id}/choices", $this->form(['evidence_reference' => 'guest@example.test']))->assertSessionHas('error');

        $this->assertSame(0, PaymentExceptionChoice::count());
        Log::shouldNotHaveReceived('error');
        $this->assertStringNotContainsString('example.test', AuditLog::pluck('description')->implode(' '));
    }

    public function test_a_stale_or_missing_lock_version_is_refused(): void
    {
        $exception = $this->exception();
        $choice = app(GuestChoiceService::class)->record($exception, $this->staffUser('admin'), array_merge($this->form(), ['evidence_at' => now()->subHour()->toIso8601String()]));
        $owner = $this->staffUser('owner');

        $this->actingAs($owner)->post("/owner/payments/exceptions/{$exception->id}/choices/{$choice->id}/verify", ['verification_note' => 'Synthetic: confirmed with the guest.'])
            ->assertSessionHas('error');
        $this->actingAs($owner)->post("/owner/payments/exceptions/{$exception->id}/choices/{$choice->id}/verify", ['verification_note' => 'Synthetic: confirmed with the guest.', 'lock_version' => '3'])
            ->assertSessionHas('error');
        $this->assertSame(PaymentExceptionChoice::RECORDED, $choice->fresh()->status);

        $other = $this->exception();
        $this->actingAs($owner)->post("/owner/payments/exceptions/{$other->id}/choices/{$choice->id}/verify", ['verification_note' => 'Synthetic: confirmed with the guest.', 'lock_version' => '0'])
            ->assertNotFound();
    }

    public function test_allocation_from_the_screen_is_blocked_by_default_and_recorded(): void
    {
        $exception = $this->exception();
        $service = app(GuestChoiceService::class);
        $choice = $service->record($exception, $this->staffUser('admin'), array_merge($this->form(), ['choice' => 'alternative_date', 'requested_start_date' => '2026-12-26',
            'requested_end_date' => '2026-12-27', 'evidence_at' => now()->subHour()->toIso8601String(), 'evidence_summary' => 'Synthetic: guest asked for Dec 26 instead.']));
        $service->verify($choice, $this->staffUser('owner'), 'Synthetic: confirmed with the booking email.', 0);

        $this->actingAs($this->staffUser('admin'))->get("/admin/payments/exceptions/{$exception->id}")->assertOk()->assertSee('Allocate verified date');
        $this->actingAs($this->staffUser('admin'))->post("/admin/payments/exceptions/{$exception->id}/choices/{$choice->id}/allocate", ['lock_version' => '1'])
            ->assertRedirect("/admin/payments/exceptions/{$exception->id}")->assertSessionHas('error');

        $this->assertSame(PaymentExceptionChoice::VERIFIED, $choice->fresh()->status);
        $this->assertSame('open', $exception->fresh()->status);
        $this->assertSame(['blocked'], $exception->allocationAttempts()->pluck('outcome')->all());
    }

    public function test_requests_without_csrf_token_are_rejected(): void
    {
        $exception = $this->exception();
        $this->withMiddleware()->actingAs($this->staffUser('admin'));
        $this->app['env'] = 'production';
        $response = $this->call('POST', "/admin/payments/exceptions/{$exception->id}/choices", $this->form());
        $this->assertSame(419, $response->status());
        $this->assertSame(0, PaymentExceptionChoice::count());
    }
}
