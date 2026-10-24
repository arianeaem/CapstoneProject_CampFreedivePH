<?php

namespace Tests\Feature\Checkout;

use App\Jobs\SendCheckoutNotification;
use App\Mail\AdminOperationalNotificationMail;
use App\Mail\BookingConfirmedMail;
use App\Mail\CheckoutPaymentReceivedMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\CheckoutNotification;
use App\Models\Payment;
use App\Services\Checkout\CheckoutNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\TestCase;

/**
 * Batch 2 hardening: checkout messages through the durable outbox (record after commit -> queued job -> atomic
 * claim -> mark sent only after the mailer returns; backoff, stale-claim recovery, bounded attempts, failed kept for
 * staff). Queue and mail are faked; "the worker" is deliverQueuedCheckoutNotifications(). Faked PayMongo only.
 */
class CheckoutNotificationTest extends TestCase
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

    // ---------------------------------------------------------------- helpers

    private function paysOnPayMongo(CheckoutIntent $intent, ?array $body = null): void
    {
        $this->fakeSessionRetrieval($intent->paymongo_checkout_session_id, $body ?? $this->paidSessionBody($intent));
    }

    /** A confirmed booking from a verified return; messages recorded and queued, not delivered yet. */
    private function confirmedCheckout(): CheckoutIntent
    {
        $intent = $this->checkoutIntent();
        $this->paysOnPayMongo($intent);
        $this->get($this->signedCheckoutUrl($intent))->assertOk()->assertSee('Booking confirmed');

        return $intent->fresh();
    }

    /** Mail that throws for $failing addresses (all when empty), as an SMTP outage or a rejected recipient would. */
    private function mailFails(array $failing = []): void
    {
        Mail::swap(new class(new MailManager(app()), $failing) extends MailFake {
            public function __construct(MailManager $manager, private array $failing)
            {
                parent::__construct($manager);
            }

            public function send($view, array $data = [], $callback = null)
            {
                if ($view instanceof Mailable && ($this->failing === [] || collect($this->failing)->contains(fn ($to) => $view->hasTo($to)))) {
                    throw new TransportException('Synthetic SMTP failure');
                }

                return parent::send($view, $data, $callback);
            }
        });
    }

    private function sweep()
    {
        return $this->artisan('checkout:notifications');
    }

    private function rows(CheckoutIntent $intent)
    {
        return CheckoutNotification::where('checkout_intent_id', $intent->id)->orderBy('id')->get();
    }

    // ------------------------------------------------------------ delivery basics

    public function test_confirmed_booking_queues_messages_and_the_worker_delivers_them_once(): void
    {
        $owner = $this->staffUser('owner');
        $admin = $this->staffUser('admin');
        $this->staffUser('coach');

        $intent = $this->confirmedCheckout();

        Mail::assertNothingSent(); // never inside the return request
        $this->assertSame([CheckoutNotification::CUSTOMER_BOOKING_CONFIRMED, CheckoutNotification::STAFF_NEW_BOOKING, CheckoutNotification::STAFF_NEW_BOOKING],
            $this->rows($intent)->pluck('kind')->all(), 'Customer plus one row per owner/admin; never a coach.');
        $this->assertNotNull($intent->notifications_recorded_at);

        $this->deliverQueuedCheckoutNotifications();
        $this->deliverQueuedCheckoutNotifications(); // duplicate job runs change nothing

        $booking = Booking::sole();
        Mail::assertSent(BookingConfirmedMail::class, 1);
        Mail::assertSent(BookingConfirmedMail::class, fn ($m) => $m->hasTo(self::CONTACT_EMAIL) && $m->booking->is($booking));
        Mail::assertSent(AdminOperationalNotificationMail::class, 2);
        foreach ([$owner, $admin] as $staff) {
            Mail::assertSent(AdminOperationalNotificationMail::class, fn ($m) => $m->hasTo($staff->email));
        }
        $this->assertSame(['sent', 'sent', 'sent'], $this->rows($intent)->pluck('status')->all());
        $this->assertSame([1, 1, 1], $this->rows($intent)->pluck('attempts')->all());
        $this->assertNotNull($intent->fresh()->customer_notified_at);
        $this->assertNotNull($intent->fresh()->staff_notified_at);
    }

    public function test_nothing_is_recorded_or_sent_before_a_verified_payment(): void
    {
        $this->staffUser('admin');
        $unpaid = $this->checkoutIntent();
        $this->paysOnPayMongo($unpaid, $this->sessionBody($unpaid, []));
        $review = $this->checkoutIntent();
        $this->paysOnPayMongo($review, $this->paidSessionBody($review, ['attributes' => ['amount' => 100]])); // amount mismatch
        $authorizing = $this->checkoutIntent();
        $body = $this->sessionBody($authorizing, []);
        $body['data']['attributes']['payment_intent']['attributes']['status'] = 'awaiting_next_action';
        $this->paysOnPayMongo($authorizing, $body);

        foreach ([$unpaid, $review, $authorizing] as $intent) {
            $this->get($this->signedCheckoutUrl($intent))->assertOk()->assertDontSee('Booking confirmed');
        }
        $this->get($this->signedCheckoutUrl($unpaid, 'checkout.cancel'))->assertOk();
        $this->sweep()->expectsOutputToContain('recorded=0 recovered=0 queued=0')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $review->fresh()->status);
        $this->assertSame(0, CheckoutNotification::count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        $this->assertSame(0, Booking::count());
    }

    public function test_paid_but_full_date_sends_payment_received_and_a_critical_staff_alert_never_a_confirmation(): void
    {
        $this->staffUser('admin');
        $intent = $this->checkoutIntent();
        $this->fillDate(5); // 6 places, checkout needs 2
        $this->paysOnPayMongo($intent);

        $this->get($this->signedCheckoutUrl($intent))->assertOk();
        $this->postSignedPayMongoWebhook($this->paidWebhookEvent($intent, 'evt_bc_full'))->assertOk();
        $this->deliverQueuedCheckoutNotifications();

        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, $intent->fresh()->status);
        Mail::assertNotSent(BookingConfirmedMail::class);
        Mail::assertSent(CheckoutPaymentReceivedMail::class, 1);
        Mail::assertSent(CheckoutPaymentReceivedMail::class, fn ($m) => $m->hasTo(self::CONTACT_EMAIL) && $m->intent->is($intent));
        Mail::assertSent(AdminOperationalNotificationMail::class, 1);
        Mail::assertSent(AdminOperationalNotificationMail::class, fn ($m) => $m->severity === 'critical' && str_contains($m->messageBody, $intent->reference));
        $this->assertSame(1, Booking::count(), 'Only the booking that filled the date.');
    }

    public function test_paid_but_full_without_customer_email_records_staff_alert_only_and_is_never_reselected(): void
    {
        $this->staffUser('owner');
        $intent = $this->checkoutIntent();
        $payload = $intent->payload;
        unset($payload['contact']['email']);
        $intent->update(['payload' => $payload]);
        $this->fillDate(5);
        $this->paysOnPayMongo($intent);

        $this->get($this->signedCheckoutUrl($intent))->assertOk();
        Carbon::setTestNow(now()->addMinutes(5));
        $this->sweep()->expectsOutputToContain('recorded=0')->assertSuccessful();

        $this->assertSame([CheckoutNotification::STAFF_PAID_UNALLOCATED], $this->rows($intent)->pluck('kind')->all());
        $this->assertNotNull($intent->fresh()->notifications_recorded_at);
        $this->actingAs($this->staffUser('admin'))->get('/admin/payments/checkout-review?queue=paid_unallocated')->assertOk()->assertSee($intent->reference);
    }

    // ------------------------------------------------------------- failures and retries

    public function test_smtp_failure_keeps_the_booking_and_retries_after_backoff(): void
    {
        $intent = $this->confirmedCheckout();
        $this->mailFails();

        $this->deliverQueuedCheckoutNotifications();

        $row = $this->rows($intent)->sole();
        $this->assertSame([CheckoutNotification::PENDING, 1, 'TransportException'], [$row->status, $row->attempts, $row->last_error]);
        $this->assertTrue($row->available_at->equalTo(now()->addMinute()), 'First backoff: 1 minute.');
        $this->assertNull($intent->fresh()->customer_notified_at, 'Not marked delivered.');
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());

        Mail::fake();
        Queue::fake();
        $this->sweep()->expectsOutputToContain('queued=0')->assertSuccessful(); // backoff not over
        Carbon::setTestNow(now()->addMinutes(2));
        $this->sweep()->expectsOutputToContain('queued=1')->assertSuccessful();
        $this->deliverQueuedCheckoutNotifications();

        Mail::assertSent(BookingConfirmedMail::class, 1);
        $this->assertSame([CheckoutNotification::SENT, 2], [$this->rows($intent)->sole()->status, $this->rows($intent)->sole()->attempts]);
        $this->assertNotNull($intent->fresh()->customer_notified_at);
    }

    public function test_one_failing_staff_recipient_is_tracked_separately_and_never_marked_sent(): void
    {
        $good = $this->staffUser('owner');
        $bad = $this->staffUser('admin');
        $intent = $this->confirmedCheckout();
        $this->mailFails([$bad->email]);

        $this->deliverQueuedCheckoutNotifications();

        $byRecipient = $this->rows($intent)->mapWithKeys(fn ($n) => [$n->recipient => $n->status]);
        $this->assertSame(CheckoutNotification::SENT, $byRecipient[self::CONTACT_EMAIL]);
        $this->assertSame(CheckoutNotification::SENT, $byRecipient[$good->email]);
        $this->assertSame(CheckoutNotification::PENDING, $byRecipient[$bad->email], 'Failed recipient stays undelivered.');
        $this->assertNotNull($intent->fresh()->staff_notified_at, 'At least one staff member was told.');

        Mail::fake();
        Queue::fake();
        Carbon::setTestNow(now()->addMinutes(2));
        $this->sweep()->expectsOutputToContain('queued=1')->assertSuccessful();
        $this->deliverQueuedCheckoutNotifications();
        Mail::assertSent(AdminOperationalNotificationMail::class, 1);
        Mail::assertSent(AdminOperationalNotificationMail::class, fn ($m) => $m->hasTo($bad->email));
        Mail::assertNotSent(BookingConfirmedMail::class); // the customer is not emailed twice
    }

    public function test_exhausted_retries_are_kept_as_failed_visible_to_staff_and_can_be_queued_again(): void
    {
        config(['checkout.notifications.max_attempts' => 2]);
        $intent = $this->confirmedCheckout();
        $this->mailFails();

        $this->deliverQueuedCheckoutNotifications();
        Carbon::setTestNow(now()->addMinutes(2));
        Queue::fake();
        $this->sweep()->expectsOutputToContain('queued=1')->assertSuccessful();
        $this->deliverQueuedCheckoutNotifications();
        Carbon::setTestNow(now()->addHours(6));
        Queue::fake();
        $this->sweep()->expectsOutputToContain('queued=0')->assertSuccessful(); // never retried automatically again

        $row = $this->rows($intent)->sole();
        $this->assertSame([CheckoutNotification::FAILED, 2], [$row->status, $row->attempts]);
        $this->assertSame(1, AuditLog::where('action', 'CHECKOUT_NOTIFICATION_FAILED')->count());
        $admin = $this->staffUser('admin');
        $this->actingAs($admin)->get('/admin/payments/checkout-review')->assertOk()->assertSee($intent->reference);
        $this->actingAs($admin)->get('/admin/payments/checkout-review?queue=delivery_failed')->assertOk()->assertSee($intent->reference);
        $this->actingAs($admin)->get("/admin/payments/checkout-review/{$intent->id}")->assertOk()->assertSee('TransportException')->assertSee('Retry failed messages');

        Mail::fake();
        $this->actingAs($admin)->post("/admin/payments/checkout-review/{$intent->id}/resend")->assertSessionHas('success');
        $this->deliverQueuedCheckoutNotifications();

        Mail::assertSent(BookingConfirmedMail::class, 1);
        $this->assertSame(CheckoutNotification::SENT, $this->rows($intent)->sole()->status);
        $this->assertSame(1, AuditLog::where('action', 'CHECKOUT_NOTIFICATION_RETRY')->where('user_id', $admin->id)->count());
        $this->actingAs($this->staffUser('coach'))->post("/admin/payments/checkout-review/{$intent->id}/resend")->assertForbidden();
    }

    // ------------------------------------------------------ crashes and concurrency

    public function test_worker_crash_after_claim_is_recovered_once_the_claim_is_stale(): void
    {
        $intent = $this->confirmedCheckout();
        $row = $this->rows($intent)->sole();
        // A worker claimed it and died before sending (no mail, no "sent")
        CheckoutNotification::whereKey($row->id)->update(['status' => CheckoutNotification::SENDING, 'claim_token' => 'aaaaaaaa-0000-4000-8000-000000000001', 'claimed_at' => now(), 'attempts' => 1]);

        app(CheckoutNotifier::class)->deliver($row->id); // a fresh claim belongs to a live worker: hands off
        Queue::fake();
        $this->sweep()->expectsOutputToContain('recovered=0 queued=0')->assertSuccessful();
        Mail::assertNothingSent();

        Carbon::setTestNow(now()->addMinutes(16));
        $this->sweep()->expectsOutputToContain('recovered=1 queued=1')->assertSuccessful();
        $this->deliverQueuedCheckoutNotifications();

        Mail::assertSent(BookingConfirmedMail::class, 1);
        $this->assertSame([CheckoutNotification::SENT, 2], [$this->rows($intent)->sole()->status, $this->rows($intent)->sole()->attempts]);
    }

    public function test_concurrent_workers_never_both_send_the_same_message(): void
    {
        $intent = $this->confirmedCheckout();
        $id = $this->rows($intent)->sole()->id;
        $notifier = app(CheckoutNotifier::class);

        // Worker B tries while worker A is mid-send: A's claim is live, so B sends nothing
        Mail::swap(new class(new MailManager(app()), $notifier, $id) extends MailFake {
            private bool $nested = false;

            public function __construct(MailManager $manager, private CheckoutNotifier $notifier, private int $id)
            {
                parent::__construct($manager);
            }

            public function send($view, array $data = [], $callback = null)
            {
                if (! $this->nested) {
                    $this->nested = true;
                    $this->notifier->deliver($this->id); // worker B, during A's send
                }

                return parent::send($view, $data, $callback);
            }
        });
        $notifier->deliver($id); // worker A
        $notifier->deliver($id); // worker C, after A finished

        Mail::assertSent(BookingConfirmedMail::class, 1);
        $this->assertSame([CheckoutNotification::SENT, 1], [$this->rows($intent)->sole()->status, $this->rows($intent)->sole()->attempts]);
    }

    public function test_return_webhook_and_reconciler_race_records_each_message_once_and_queues_boundedly(): void
    {
        $this->staffUser('admin');
        $intent = $this->checkoutIntent();
        $this->paysOnPayMongo($intent);

        $this->get($this->signedCheckoutUrl($intent))->assertOk();
        $this->postSignedPayMongoWebhook($this->paidWebhookEvent($intent, 'evt_race_1'))->assertOk();
        $this->postSignedPayMongoWebhook($this->paidWebhookEvent($intent, 'evt_race_2'))->assertOk();
        $this->get($this->signedCheckoutUrl($intent))->assertOk();
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('checkout:reconcile')->assertSuccessful();
        $this->sweep()->assertSuccessful();

        $this->assertSame(2, $this->rows($intent)->count(), 'Customer + one admin, recorded once.');
        Queue::assertPushed(SendCheckoutNotification::class, 2); // one job per message despite repeats (dispatch lease)
        $this->deliverQueuedCheckoutNotifications();
        Mail::assertSent(BookingConfirmedMail::class, 1);
        Mail::assertSent(AdminOperationalNotificationMail::class, 1);
        $this->assertSame(1, Booking::count());
    }

    public function test_outcome_committed_but_messages_never_recorded_are_recorded_by_the_sweep(): void
    {
        $intent = $this->confirmedCheckout();
        // The request died right after the booking committed: nothing reached the outbox
        CheckoutNotification::query()->delete();
        DB::table('checkout_intents')->where('id', $intent->id)->update(['notifications_recorded_at' => null]);
        Queue::fake();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->sweep()->expectsOutputToContain('recorded=1')->assertSuccessful();
        $this->deliverQueuedCheckoutNotifications();

        Mail::assertSent(BookingConfirmedMail::class, 1);
    }

    public function test_queue_failure_after_commit_loses_nothing(): void
    {
        Queue::swap(new class(app()) extends QueueFake {
            public function push($job, $data = '', $queue = null)
            {
                throw new \RuntimeException('Synthetic queue outage');
            }
        });
        $intent = $this->checkoutIntent();
        $this->paysOnPayMongo($intent);

        $this->get($this->signedCheckoutUrl($intent))->assertOk()->assertSee('Booking confirmed');

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame(CheckoutNotification::PENDING, $this->rows($intent)->sole()->status);

        Queue::fake(); // queue is back
        $this->sweep()->expectsOutputToContain('queued=0')->assertSuccessful(); // dispatch lease still running
        Carbon::setTestNow(now()->addMinutes(11));
        $this->sweep()->expectsOutputToContain('queued=1')->assertSuccessful();
        $this->deliverQueuedCheckoutNotifications();
        Mail::assertSent(BookingConfirmedMail::class, 1);
    }

    // ---------------------------------------------------------------------- privacy

    public function test_pin_and_customer_email_never_reach_staff_messages_or_logs(): void
    {
        $this->staffUser('owner');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message . ' ' . json_encode($e->context);
        });
        $intent = $this->confirmedCheckout();
        $pin = 'PZ9Q'; // distinctive stand-in, so a match cannot be a coincidence
        Booking::sole()->update(['pin' => $pin]);
        $this->mailFails(); // failures produce log lines

        $this->deliverQueuedCheckoutNotifications();
        Mail::fake();
        Queue::fake();
        Carbon::setTestNow(now()->addMinutes(2));
        $this->sweep()->assertSuccessful();
        $this->deliverQueuedCheckoutNotifications();

        Mail::assertSent(BookingConfirmedMail::class, fn ($m) => $m->booking->pin === $pin); // the customer gets it
        Mail::assertSent(AdminOperationalNotificationMail::class, fn ($m) => ! str_contains($m->messageBody . json_encode($m->details), $pin)
            && ! str_contains(json_encode($m->details), self::CONTACT_EMAIL));
        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($pin, $line);
            $this->assertStringNotContainsString(self::CONTACT_EMAIL, $line);
        }
        $this->assertStringNotContainsString(self::CONTACT_EMAIL, (string) DB::table('checkout_notifications')->value('recipient'), 'Stored encrypted.');
    }

    public function test_cancel_page_sends_nothing_and_changes_nothing(): void
    {
        $intent = $this->checkoutIntent();
        Http::fake();

        $this->get($this->signedCheckoutUrl($intent, 'checkout.cancel'))->assertOk();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }
}
