<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BookingRefund;
use App\Models\RefundAttempt;
use App\Services\Refunds\RefundCaseService;
use App\Services\Refunds\RefundExecutor;
use App\Services\Refunds\RefundLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Owner/admin refund cases (same access as the existing refund queue; coaches never reach it).
 * Each step is separate and audited: confirm reason and progress, record the guest's choice, decide earlier-terms
 * cases, approve an amount (capped by RefundLedger::entitlement), pay out (RefundExecutor), look unclear PayMongo
 * outcomes up at PayMongo (never settled by staff notes), or document offline payouts with evidence. No step pays out
 * an unconfirmed balance; "no refund" decisions go through RefundGuard.
 */
class RefundCaseController extends Controller
{
    public function __construct(
        protected RefundCaseService $cases,
        protected RefundLedger $ledger,
    ) {
    }

    public function index(Request $request): View
    {
        $request->validate(['status' => ['nullable', Rule::in(['open', 'all', ...array_keys($this->statusLabels())])]]);
        $status = $request->input('status', 'open');

        $cases = BookingRefund::with('booking')
            ->when($status === 'open', fn ($q) => $q->whereIn('status', BookingRefund::OPEN_STATUSES))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString();

        return view('admin.payments.refund_cases.index', [
            'cases' => $cases,
            'status' => $status,
            'labels' => $this->statusLabels(),
            'counts' => BookingRefund::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(BookingRefund $refundCase): View
    {
        $refundCase->load(['booking', 'cancellationRequest', 'attempts.payment']);

        return view('admin.payments.refund_cases.show', [
            'case' => $refundCase,
            'position' => $this->ledger->position($refundCase->booking),
            'entitlement' => $this->ledger->entitlement($refundCase),
            'labels' => $this->statusLabels(),
            'history' => AuditLog::where('description', 'like', "Refund case #{$refundCase->id} %")->latest('created_at')->limit(50)->get(),
            'staleMinutes' => (int) config('refunds.stale_submission_minutes'),
        ]);
    }

    public function classify(Request $request, BookingRefund $refundCase): RedirectResponse
    {
        $data = $request->validate([
            'reason_category' => ['required', Rule::in(array_keys(BookingRefund::REASONS))],
            'activity_started' => ['required', Rule::in(['no', 'yes', 'unknown'])],
            'note' => ['required', 'string', 'max:500'],
        ]);

        return $this->run(fn () => $this->cases->classify($refundCase, $data['reason_category'], $data['activity_started'], $request->user(), $data['note']), 'Reason and progress recorded.');
    }

    public function choice(Request $request, BookingRefund $refundCase): RedirectResponse
    {
        $data = $request->validate([
            'guest_choice' => ['required', Rule::in(['refund', 'reschedule'])],
            'note' => ['required', 'string', 'max:500'],
        ]);

        return $this->run(fn () => $this->cases->recordGuestChoice($refundCase, $data['guest_choice'], $request->user(), $data['note']), "Guest's choice recorded.");
    }

    public function historical(Request $request, BookingRefund $refundCase): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['honor_previous_terms', 'apply_current_terms'])],
            'rationale' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->run(fn () => $this->cases->decideHistorical($refundCase, $data['decision'], $request->user(), $data['rationale']), 'Decision recorded.');
    }

    public function approve(Request $request, BookingRefund $refundCase): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        return $this->run(fn () => $this->cases->approve($refundCase, (int) round((float) $data['amount'] * 100), $request->user(), $data['note']),
            'Amount approved. No money has moved yet: use "Send refund" to pay it out.');
    }

    public function execute(Request $request, BookingRefund $refundCase): RedirectResponse
    {
        return $this->run(function () use ($request, $refundCase) {
            $result = app(RefundExecutor::class)->execute($refundCase, $request->user());

            return collect($result['outcomes'])->map(fn ($n, $o) => "{$n} " . str_replace('_', ' ', $o))->implode(', ') ?: 'nothing new to send';
        }, 'Refund processed: ');
    }

    public function close(Request $request, BookingRefund $refundCase): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:' . \App\Services\Refunds\RefundGuard::MIN_RATIONALE, 'max:500']]);

        return $this->run(fn () => $this->cases->close($refundCase, $request->user(), $data['note']), 'Case closed without a refund.');
    }

    /** Look an unclear PayMongo outcome up at PayMongo. Staff cannot set "succeeded" or "failed" themselves. */
    public function verifyAttempt(Request $request, BookingRefund $refundCase, RefundAttempt $refundAttempt): RedirectResponse
    {
        abort_unless((int) $refundAttempt->booking_refund_id === (int) $refundCase->id, 404);
        $data = $request->validate([
            'provider_refund_id' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->run(fn () => $this->cases->verifyAttempt($refundAttempt, $data['provider_refund_id'] ?? null, $request->user(), $data['note'] ?? null),
            'PayMongo check: ');
    }

    public function confirmOffline(Request $request, BookingRefund $refundCase, RefundAttempt $refundAttempt): RedirectResponse
    {
        abort_unless((int) $refundAttempt->booking_refund_id === (int) $refundCase->id, 404);
        $data = $request->validate(['evidence' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->run(fn () => $this->cases->confirmOfflinePayout($refundAttempt, $request->user(), $data['evidence']), 'Offline payout confirmed.');
    }

    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $result = $action();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', is_string($result) ? $success . $result : $success);
    }

    private function statusLabels(): array
    {
        return [
            BookingRefund::AWAITING_GUEST_CHOICE => "Waiting for the guest's choice",
            BookingRefund::AWAITING_STAFF_REVIEW => 'Needs staff review',
            BookingRefund::APPROVED => 'Approved (payout in progress)',
            BookingRefund::CLOSED_NO_REFUND => 'Closed, no refund',
            BookingRefund::COMPLETED => 'Refunded',
        ];
    }
}
