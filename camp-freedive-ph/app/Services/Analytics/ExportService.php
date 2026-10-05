<?php

namespace App\Services\Analytics;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Payment;
use App\Support\Xlsx\XlsxSheet;
use App\Support\Xlsx\XlsxWorkbook as X;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Excel (.xlsx) exports for Reports & Analytics: Revenue, Bookings, Batches & Coaches.
 * All three share one layout (title block, "at a glance" summary, list sheets with filters)
 * and use the same numbers as the Reports screen and the print summary (AnalyticsService).
 */
class ExportService
{
    public const TYPES = [
        'revenue' => 'Revenue',
        'bookings' => 'Bookings',
        'batches' => 'Batches & Coaches',
    ];

    public function __construct(protected AnalyticsService $analytics)
    {
    }

    public function download(string $type, array $range, bool $isOwner = true): Response
    {
        $type = match ($type) {
            'financials', 'revenue' => 'revenue',
            'batches', 'coaches', 'operations' => 'batches',
            default => 'bookings', // also the old 'divers'
        };
        if ($type === 'revenue' && !$isOwner) {
            abort(403, 'Revenue exports are only available to owners.');
        }

        $data = $this->analytics->getAnalyticsReport($range, $isOwner);
        $book = new X();
        match ($type) {
            'revenue' => $this->revenue($book, $range, $data),
            'batches' => $this->batches($book, $range, $data),
            default => $this->bookings($book, $range, $data),
        };

        $filename = 'camp-freediveph-' . $type . '-' . $range['start']->format('Ymd') . '-to-' . $range['end']->format('Ymd') . '.xlsx';

        return response($book->toString(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    // ------------------------------------------------------------------ shared

    protected function summarySheet(X $book, string $title, array $range): XlsxSheet
    {
        $period = $range['start']->format('M d, Y') . ' – ' . $range['end']->format('M d, Y');
        $user = Auth::user();

        return $book->addSheet('Summary')
            ->widths([38, 20, 20, 20, 20])
            ->titleBlock(
                "Camp FreedivePH · {$title}",
                "Period: {$range['label']} ({$period})",
                'Made on ' . Carbon::now('Asia/Manila')->format('M d, Y g:i A') . ($user ? " by {$user->name}" : '')
            );
    }

    protected function change(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    protected function pct(?float $v): array
    {
        return $v === null ? ['No earlier data', X::NOTE] : [$v, X::PERCENT];
    }

    // ------------------------------------------------------------------ revenue

    protected function revenue(X $book, array $range, array $data): void
    {
        $fin = $data['financials'];
        [$start, $end, $pStart, $pEnd] = [$range['start'], $range['end'], $range['prior_start'], $range['prior_end']];
        $paid = ['completed', 'paid'];

        $prior = Payment::query()->selectRaw("
                COALESCE(SUM(CASE WHEN status IN ('completed', 'paid') THEN amount END), 0) AS gross,
                COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount_refunded END), 0) AS refunded
            ")->whereBetween('created_at', [$pStart, $pEnd])->first();
        $priorGross = (float) $prior->gross;
        $priorRefunded = (float) $prior->refunded;
        $priorNet = max(0, $priorGross - $priorRefunded);
        $priorBookings = Booking::whereBetween('created_at', [$pStart, $pEnd])->count();

        $s = $this->summarySheet($book, 'Revenue report', $range);

        $s->section('Money at a glance')->header(['What', 'Amount']);
        $s->row(['Money collected', (float) $fin['gross_revenue']])
            ->row(['Refunded to guests', (float) $fin['refunds_processed']])
            ->row([['Money kept (collected minus refunds)', X::TOTAL_TEXT], [(float) $fin['net_revenue'], X::TOTAL_PESO]])
            ->row(['Paid as deposit', (float) $fin['downpayment_revenue']])
            ->row(['Paid in full', (float) $fin['balance_revenue']])
            ->row(['Still to collect (unpaid balances)', (float) $fin['outstanding_receivables']])
            ->row(['Average per diver', (float) $fin['arpd']])
            ->row(['Average per booking', (float) $fin['arpb']])
            ->blank();

        // Monthly totals inside the period, each compared with the month before it
        $s->section('Monthly/Yearly Total Revenue and Growth (%)', 'Money collected each month, and how it changed from the month before')
            ->header(['Month', 'Money collected', 'Change vs month before']);
        $monthly = Payment::whereIn('status', $paid)->whereBetween('created_at', [$start->copy()->startOfMonth()->subMonth(), $end])
            ->get(['amount', 'created_at'])
            ->groupBy(fn ($p) => $p->created_at->format('Y-m'))
            ->map(fn ($rows) => (float) $rows->sum('amount'));
        for ($m = $start->copy()->startOfMonth(); $m->lte($end); $m->addMonth()) {
            $amount = (float) ($monthly[$m->format('Y-m')] ?? 0);
            $before = (float) ($monthly[$m->copy()->subMonth()->format('Y-m')] ?? 0);
            $s->row([$m->format('F Y'), $amount, $m->isFuture() ? ['Not reached yet', X::NOTE] : $this->pct($this->change($amount, $before))]);
        }
        $s->row([['Total for the period', X::TOTAL_TEXT], [(float) $fin['gross_revenue'], X::TOTAL_PESO],
            $this->change((float) $fin['gross_revenue'], $priorGross) === null ? ['', X::TOTAL_TEXT] : [$this->change((float) $fin['gross_revenue'], $priorGross), X::TOTAL_PERCENT]])
            ->blank();

        $days = max(1, $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
        $months = max(1, $start->copy()->startOfMonth()->diffInMonths($end->copy()->startOfMonth()) + 1);
        $s->section('Weekly/Monthly Average Revenue', 'Money collected divided by the number of weeks or months in the period')
            ->header(['Average', 'Amount'])
            ->row(['Per week', round((float) $fin['gross_revenue'] / max(1, ceil($days / 7)), 2)])
            ->row(['Per month', round((float) $fin['gross_revenue'] / $months, 2)])
            ->blank();

        $s->section('Actual vs. Last Comparison', 'This period compared with the same length of time just before it')
            ->header(['What', 'This period', 'Previous period', 'Change'])
            ->row(['Money collected', (float) $fin['gross_revenue'], $priorGross, $this->pct($this->change((float) $fin['gross_revenue'], $priorGross))])
            ->row(['Refunded', (float) $fin['refunds_processed'], $priorRefunded, $this->pct($this->change((float) $fin['refunds_processed'], $priorRefunded))])
            ->row(['Money kept', (float) $fin['net_revenue'], $priorNet, $this->pct($this->change((float) $fin['net_revenue'], $priorNet))])
            ->row(['Bookings made', (int) $data['bookings']['total_bookings'], $priorBookings, $this->pct($this->change((float) $data['bookings']['total_bookings'], $priorBookings))])
            ->blank();

        $s->section('Revenue by Class', 'Money collected per package')
            ->header(['Package', 'Paid bookings', 'Divers', 'Money collected', 'Share of money']);
        foreach ($fin['packages'] as $pkg) {
            $s->row([$pkg['name'], (int) $pkg['bookings_count'], (int) $pkg['pax_count'], (float) $pkg['revenue'], [(float) $pkg['share_percentage'], X::PERCENT]]);
        }
        $pkgs = collect($fin['packages']);
        $s->row([['Total', X::TOTAL_TEXT], [(int) $pkgs->sum('bookings_count'), X::TOTAL_NUMBER], [(int) $pkgs->sum('pax_count'), X::TOTAL_NUMBER],
            [(float) $pkgs->sum('revenue'), X::TOTAL_PESO], [round((float) $pkgs->sum('share_percentage'), 1), X::TOTAL_PERCENT]])
            ->blank();

        $dp = $fin['dynamic_pricing'];
        $s->section('Individual Revenue Chart for Other Services', 'Add-ons are estimates: number of divers × the add-on price')
            ->header(['Extra', 'Bookings', 'Divers', 'Money'])
            ->row(['Carpool from Manila', (int) $fin['carpool']['bookings_count'], (int) $fin['carpool']['pax_count'], (float) $fin['carpool']['estimated_revenue']])
            ->row(['Boat dive', (int) $fin['boat_dive']['bookings_count'], (int) $fin['boat_dive']['pax_count'], (float) $fin['boat_dive']['estimated_revenue']])
            ->row(['Busy-day pricing (extra)', '', '', (float) $dp['positive_yield']])
            ->row(['Discounts given', '', '', -1 * (float) $dp['discounts_given']])
            ->blank();

        $s->section('Quota/Breakeven', 'No money target is set up in the system yet')
            ->header(['What', 'Result'])
            ->row(['Money target for the period', 'Not set'])
            ->row(['Breakeven target', 'Not set'])
            ->row(['Result', $fin['net_revenue'] > 0 ? 'The camp kept ₱' . number_format($fin['net_revenue'], 2) . ' this period' : 'No money kept this period']);

        // Payments list
        $payments = Payment::with('booking')
            ->whereIn('status', array_merge($paid, ['refunded', 'partially_refunded']))
            ->whereBetween('created_at', [$start, $end])
            ->latest('created_at')
            ->get();
        $book->addSheet('Payments')
            ->widths([20, 16, 26, 14, 16, 16, 16, 14, 16])
            ->section('Overall Historical Transaction List for Payments and Refunds', 'Every payment and refund in this period')
            ->table(
                ['Date', 'Booking', 'Guest', 'Package', 'Payment', 'Amount', 'Refunded', 'Method', 'Status'],
                $payments->map(fn ($p) => [
                    $p->created_at->copy()->setTimezone('Asia/Manila')->format('M d, Y g:i A'),
                    $p->booking?->booking_number ?? '#' . $p->booking_id,
                    $p->booking?->contact_name ?? '',
                    ucfirst($p->booking?->class_type ?? ''),
                    match ($p->payment_type) { 'downpayment' => 'Deposit', 'full' => 'Paid in full', 'balance_settlement' => 'Balance', default => ucwords(str_replace('_', ' ', (string) $p->payment_type)) },
                    (float) $p->amount,
                    (float) ($p->amount_refunded ?? 0),
                    ucwords(str_replace('_', ' ', (string) ($p->payment_method ?? ''))),
                    match ($p->status) { 'completed', 'paid' => 'Paid', 'refunded' => 'Refunded', 'partially_refunded' => 'Partly refunded', default => ucfirst($p->status) },
                ])
            );
    }

    // ------------------------------------------------------------------ bookings

    protected function bookings(X $book, array $range, array $data): void
    {
        $b = $data['bookings'];
        $total = (int) $b['total_bookings'];
        $share = fn ($n, $of) => [$of > 0 ? round($n / $of * 100, 1) : 0, X::PERCENT];

        $s = $this->summarySheet($book, 'Bookings report', $range);

        $s->section('Bookings at a glance')->header(['What', 'Count', 'Share'])
            ->row(['Bookings made', $total])
            ->row(['Divers in those bookings', (int) $b['total_participants']])
            ->row(['Paid the downpayment', (int) $b['paid_bookings'], $share($b['paid_bookings'], $total)])
            ->row(['Cancelled', (int) $b['cancelled_bookings'], $share($b['cancelled_bookings'], $total)])
            ->row(['Reschedule requests', (int) $b['reschedule_count']])
            ->row(['Cancel requests', (int) $b['cancellation_count']])
            ->row(['Change in bookings vs previous period', [(float) $b['booking_delta'], X::PERCENT]])
            ->blank();

        $g = $b['status_groups'];
        $s->section('Where every booking stands', 'Each booking is counted once, so these add up to the total')
            ->header(['Status', 'Bookings', 'Share'])
            ->row(['Paid & going', (int) $g['going'], $share($g['going'], $total)])
            ->row(['Finished trip', (int) $g['completed'], $share($g['completed'], $total)])
            ->row(['Waiting for downpayment', (int) $g['waiting'], $share($g['waiting'], $total)])
            ->row(['Cancelled', (int) $g['cancelled'], $share($g['cancelled'], $total)])
            ->row(['No-show', (int) $g['no_show'], $share($g['no_show'], $total)])
            ->row([['Total', X::TOTAL_TEXT], [$total, X::TOTAL_NUMBER], [$total > 0 ? 100.0 : 0, X::TOTAL_PERCENT]])
            ->blank();

        $gs = $b['group_sizes'];
        $s->section('Who books together')->header(['Group', 'Bookings', 'Share'])
            ->row(['Alone (1 diver)', (int) $gs['solo'], $share($gs['solo'], $total)])
            ->row(['Pairs (2 divers)', (int) $gs['duo'], $share($gs['duo'], $total)])
            ->row(['Small groups (3–4)', (int) $gs['small_group'], $share($gs['small_group'], $total)])
            ->row(['Big groups (5 or more)', (int) $gs['large_group'], $share($gs['large_group'], $total)])
            ->row([['Total', X::TOTAL_TEXT], [(int) array_sum($gs), X::TOTAL_NUMBER]])
            ->blank();

        $sw = $b['swimmer_ability'];
        $s->section('Can Discovery divers swim?')->header(['Swimming', 'Divers', 'Share'])
            ->row(["Can't swim", (int) $sw['cannot_swim'], $share($sw['cannot_swim'], $sw['total'])])
            ->row(['Can swim', (int) $sw['can_swim'], $share($sw['can_swim'], $sw['total'])])
            ->row(['Strong swimmer', (int) $sw['strong'], $share($sw['strong'], $sw['total'])])
            ->row([['Total', X::TOTAL_TEXT], [(int) $sw['total'], X::TOTAL_NUMBER]])
            ->blank();

        $lt = $b['lead_times'];
        $leadTotal = array_sum($lt);
        $s->section('How early guests book', 'Days between making the booking and the dive')->header(['Booked', 'Bookings', 'Share'])
            ->row(['Under 4 days before', (int) $lt['under_3_days'], $share($lt['under_3_days'], $leadTotal)])
            ->row(['4–7 days before', (int) $lt['4_to_7_days'], $share($lt['4_to_7_days'], $leadTotal)])
            ->row(['1–2 weeks before', (int) $lt['8_to_14_days'], $share($lt['8_to_14_days'], $leadTotal)])
            ->row(['15 or more days before', (int) ($lt['15_to_30_days'] + $lt['over_30_days']), $share($lt['15_to_30_days'] + $lt['over_30_days'], $leadTotal)])
            ->row([['Total', X::TOTAL_TEXT], [(int) $leadTotal, X::TOTAL_NUMBER]]);

        // Bookings list (same bookings as the summary)
        $paidByBooking = Payment::whereIn('booking_id', $b['bookings_list']->pluck('id'))
            ->whereIn('status', ['completed', 'paid'])
            ->selectRaw('booking_id, SUM(amount) AS s')->groupBy('booking_id')->pluck('s', 'booking_id');
        $book->addSheet('Bookings')
            ->widths([16, 14, 24, 28, 16, 12, 8, 14, 14, 16, 24, 14, 14, 14, 10, 10])
            ->table(
                ['Booking', 'Booked on', 'Guest', 'Email', 'Phone', 'Package', 'Divers', 'Trip starts', 'Trip ends', 'Batch', 'Status', 'Price', 'Paid', 'Still to pay', 'Carpool', 'Boat dive'],
                $b['bookings_list']->map(function ($bk) use ($paidByBooking) {
                    $paidAmount = (float) ($paidByBooking[$bk->id] ?? 0);
                    $owing = in_array(AnalyticsService::bookingGroup($bk->status), ['going', 'completed'], true) ? max(0, (float) $bk->total_amount - $paidAmount) : 0;

                    return [
                        $bk->booking_number,
                        $bk->created_at ? $bk->created_at->copy()->startOfDay() : '',
                        $bk->contact_name,
                        $bk->contact_email,
                        $bk->contact_phone,
                        ucfirst($bk->class_type ?? ''),
                        $bk->participants->count(),
                        $bk->start_date ?: '',
                        $bk->end_date ?: '',
                        $bk->batch?->batch_number ?? 'No batch yet',
                        AnalyticsService::bookingStatusLabel($bk->status),
                        (float) $bk->total_amount,
                        $paidAmount,
                        $owing,
                        $bk->pickup_option === 'carpool' ? 'Yes' : 'No',
                        $bk->boat_dive ? 'Yes' : 'No',
                    ];
                })
            );

        // Divers list
        $participants = BookingParticipant::with('booking')
            ->whereIn('booking_id', $b['bookings_list']->pluck('id'))
            ->orderBy('booking_id')
            ->get();
        $book->addSheet('Divers')
            ->widths([26, 16, 12, 8, 16, 32, 24, 16, 14, 24])
            ->table(
                ['Diver', 'Booking', 'Package', 'Age', 'Swimming', 'Health notes', 'Booked by', 'Phone', 'Trip starts', 'Status'],
                $participants->map(fn ($p) => [
                    $p->name,
                    $p->booking?->booking_number ?? '',
                    ucfirst($p->booking?->class_type ?? ''),
                    $p->age !== null ? (int) $p->age : '',
                    AnalyticsService::swimLabel($p->swimmer_status),
                    $p->health_condition ?: 'None declared',
                    $p->booking?->contact_name ?? '',
                    $p->booking?->contact_phone ?? '',
                    $p->booking?->start_date ?: '',
                    AnalyticsService::bookingStatusLabel($p->booking?->status),
                ])
            );
    }

    // ------------------------------------------------------------------ batches & coaches

    protected function batches(X $book, array $range, array $data): void
    {
        $op = $data['operations'];
        $co = $data['coaches'];
        $ratio = (int) ($op['coach_ratio'] ?? 4);

        $s = $this->summarySheet($book, 'Batches & coaches report', $range);
        $s->section('Batches at a glance')->header(['What', 'Count'])
            ->row(['Batches', (int) $op['total_batches']])
            ->row(['  Still to run', (int) $op['active_batches']])
            ->row(['  Finished', (int) $op['completed_batches']])
            ->row(['  Cancelled', (int) $op['cancelled_batches']])
            ->row(['Diver slots booked', (int) $op['total_booked_pax']])
            ->row(['Diver slots available', (int) $op['total_capacity_slots']])
            ->row(['How full batches are', [(float) $op['avg_occupancy'], X::PERCENT]])
            ->row(['Batches almost full (90% or more)', (int) $op['full_capacity_batches']])
            ->blank();

        $s->section('Coaches at a glance', "Rule: 1 coach for every {$ratio} divers")->header(['What', 'Count'])
            ->row(['Batches with enough coaches', (int) $op['compliant_batches_count'], ['out of ' . (int) $op['running_batches_count'] . ' running batches', X::NOTE]])
            ->row(['Batches with enough coaches (%)', [(float) $op['safety_compliance_rate'], X::PERCENT]])
            ->row(['Coach trips', (int) $co['total_assignments_period']])
            ->row(['Coach dive days', (int) $co['total_dive_days']])
            ->row(['Active coaches', (int) $co['total_active_coaches']]);

        $book->addSheet('Batches')
            ->widths([16, 14, 14, 16, 10, 10, 10, 32, 10, 10, 18, 16])
            ->table(
                ['Batch', 'Starts', 'Ends', 'Status', 'Divers', 'Slots', 'Full', 'Coaches', 'Coaches', 'Needed', 'Enough coaches?', 'Sea safety'],
                $op['batches_list']->map(function ($batch) use ($ratio) {
                    $pax = $batch->total_participants_count;
                    $cap = $batch->computed_capacity;
                    $coaches = $batch->assigned_coaches;
                    $needed = $pax > 0 ? (int) ceil($pax / $ratio) : 0;
                    $cancelled = in_array($batch->status, AnalyticsService::BATCH_GROUPS['cancelled'], true);

                    return [
                        $batch->batch_number,
                        $batch->start_date ?: '',
                        $batch->end_date ?: '',
                        AnalyticsService::batchStatusLabel($batch->status),
                        $pax,
                        $cap,
                        [$cap > 0 ? round($pax / $cap * 100, 1) : 0, X::PERCENT],
                        $coaches->pluck('name')->implode(', ') ?: 'None yet',
                        $coaches->count(),
                        $needed,
                        $cancelled ? '—' : ($pax === 0 ? 'No divers yet' : ($coaches->count() >= $needed ? 'Yes' : 'Needs ' . ($needed - $coaches->count()) . ' more')),
                        ucwords(str_replace('_', ' ', (string) ($batch->risk_classification ?? 'Not checked'))),
                    ];
                })
            );

        $book->addSheet('Coaches')
            ->widths([26, 30, 12, 10, 12, 20])
            ->table(
                ['Coach', 'Email', 'Account', 'Batches', 'Dive days', 'Asked to be released'],
                collect($co['coaches'])->map(fn ($c) => [
                    $c['name'], $c['email'], ucfirst($c['status']), (int) $c['assignments_count'], (int) $c['estimated_dive_days'], (int) $c['releases_count'],
                ])
            );
    }
}
