<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CheckoutIntent;
use App\Models\CheckoutNotification;
use App\Services\AuditLogger;
use App\Services\Checkout\CheckoutNotifier;
use App\Services\Checkout\CheckoutPaymentProcessor;
use App\Services\Checkout\CheckoutReconciler;
use App\Services\Checkout\FinalizationResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Owner/admin review of checkout payments that did not end in a booking automatically: paid but no room, payment
 * review, paid but not finished, unresolved payment state, and checks that ran out. Coaches never reach it (role
 * middleware on the owner/admin route groups).
 *
 * Actions: a fresh PayMongo re-check through the normal processor (same verification, locks, capacity re-check and
 * outbox messages), and re-queuing messages whose delivery failed. No refunds, no money movement, no manual booking:
 * those stay manual decisions outside this screen. A resolution flag never hides a paid or under-review checkout,
 * and a checkout whose email delivery failed is listed until it is delivered.
 */
class CheckoutReviewController extends Controller
{
    public const QUEUES = [
        CheckoutIntent::STATUS_PAID_UNALLOCATED => 'Paid, date full (no booking)',
        CheckoutIntent::STATUS_PAYMENT_REVIEW => 'Payment review',
        CheckoutIntent::STATUS_PAID => 'Paid, booking not finished',
        CheckoutReconciler::AUTHORIZATION_UNRESOLVED => 'Payment state unresolved',
        CheckoutReconciler::NEEDS_STAFF_CHECK => 'Automatic checks ended',
        self::DELIVERY_FAILED => 'Email delivery failed',
    ];

    private const DELIVERY_FAILED = 'delivery_failed';

    private const RECHECKABLE = [CheckoutIntent::STATUS_PENDING, CheckoutIntent::STATUS_PAYMENT_REVIEW, CheckoutIntent::STATUS_PAID];

    public function __construct(protected CheckoutPaymentProcessor $processor, protected CheckoutNotifier $notifier)
    {
    }

    public function index(Request $request): View
    {
        $request->validate(['queue' => ['nullable', Rule::in(array_keys(self::QUEUES))]]);
        $queue = $request->input('queue');

        return view('admin.payments.checkout_review.index', [
            'intents' => $this->open($queue)->orderByDesc('updated_at')->paginate(20)->withQueryString(),
            'counts' => collect(self::QUEUES)->map(fn ($label, $key) => $this->open($key)->count()),
            'queues' => self::QUEUES,
            'queue' => $queue,
        ]);
    }

    public function show(CheckoutIntent $checkoutIntent): View
    {
        return view('admin.payments.checkout_review.show', [
            'intent' => $checkoutIntent,
            'contact' => $checkoutIntent->payload['contact'] ?? [],
            'history' => AuditLog::where('description', 'like', '%' . $checkoutIntent->reference . '%')->latest('created_at')->limit(50)->get(),
            'canRecheck' => $this->canRecheck($checkoutIntent),
            'messages' => $checkoutIntent->notifications()->orderBy('id')->get(),
        ]);
    }

    /** POST: queue this checkout's failed messages again (fresh attempt budget). No payment or booking change. */
    public function resend(Request $request, CheckoutIntent $checkoutIntent): RedirectResponse
    {
        $count = $this->notifier->retryFailed($checkoutIntent);
        if ($count === 0) {
            return back()->with('error', 'There are no failed messages to send again.');
        }

        AuditLogger::log('CHECKOUT_NOTIFICATION_RETRY', "Checkout {$checkoutIntent->reference}: {$count} failed message(s) queued again by staff.", $request->user(), null, $request);

        return back()->with('success', "{$count} message(s) queued again. Delivery status updates below.");
    }

    /** POST: fresh PayMongo verification through the normal path; never moves money. */
    public function recheck(Request $request, CheckoutIntent $checkoutIntent): RedirectResponse
    {
        if (! $this->canRecheck($checkoutIntent)) {
            return back()->with('error', 'This checkout is already decided; there is nothing to re-check with PayMongo.');
        }

        try {
            $result = $this->processor->process($checkoutIntent, 'staff_recheck');
        } catch (Throwable $e) {
            Log::error('Staff checkout re-check failed.', ['checkout_intent_id' => $checkoutIntent->id, 'exception' => class_basename($e)]);

            return back()->with('error', 'PayMongo could not be checked right now. Nothing was changed; please try again later.');
        }

        AuditLogger::log('CHECKOUT_STAFF_RECHECK', "Checkout {$checkoutIntent->reference}: payment re-checked with PayMongo by staff; outcome {$result->outcome}" . ($result->reason ? " ({$result->reason})" : '') . '.', $request->user(), null, $request);

        return back()->with('success', match ($result->outcome) {
            FinalizationResult::FINALIZED, FinalizationResult::ALREADY_FINALIZED => 'Payment verified; the booking is confirmed and the customer was emailed.',
            FinalizationResult::PAID_UNALLOCATED => 'Payment verified, but the date is full. No booking was created; this needs a manual decision.',
            FinalizationResult::PAYMENT_REVIEW => 'The payment still needs review: ' . ($result->reason ?? 'see the history below') . '.',
            FinalizationResult::NOT_PAID => 'PayMongo shows no completed payment yet (' . ($result->reason ?? 'not paid') . '). Nothing was changed.',
            default => 'PayMongo could not confirm the payment right now (' . ($result->reason ?? 'try again later') . '). Nothing was changed.',
        });
    }

    /** Open exception cases, optionally one queue. */
    private function open(?string $queue): Builder
    {
        $flagged = [CheckoutReconciler::AUTHORIZATION_UNRESOLVED, CheckoutReconciler::NEEDS_STAFF_CHECK];

        $failedMessage = fn (Builder $n) => $n->where('status', CheckoutNotification::FAILED);

        return CheckoutIntent::query()->where(fn (Builder $q) => match ($queue) {
            CheckoutIntent::STATUS_PAID_UNALLOCATED, CheckoutIntent::STATUS_PAYMENT_REVIEW, CheckoutIntent::STATUS_PAID => $q->where('status', $queue),
            CheckoutReconciler::AUTHORIZATION_UNRESOLVED, CheckoutReconciler::NEEDS_STAFF_CHECK => $q->where('status', CheckoutIntent::STATUS_PENDING)->where('resolution_status', $queue),
            self::DELIVERY_FAILED => $q->whereHas('notifications', $failedMessage),
            default => $q->whereIn('status', [CheckoutIntent::STATUS_PAID_UNALLOCATED, CheckoutIntent::STATUS_PAYMENT_REVIEW, CheckoutIntent::STATUS_PAID])
                ->orWhere(fn (Builder $p) => $p->where('status', CheckoutIntent::STATUS_PENDING)->whereIn('resolution_status', $flagged))
                ->orWhereHas('notifications', $failedMessage),
        });
    }

    private function canRecheck(CheckoutIntent $intent): bool
    {
        return in_array($intent->status, self::RECHECKABLE, true) && $intent->paymongo_checkout_session_id !== null;
    }
}
