<?php

namespace App\Services\Analytics;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingPriceAdjustment;
use App\Models\CancellationRequest;
use App\Models\Coach;
use App\Models\Payment;
use App\Models\RescheduleRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsService
{
    /** Booking statuses grouped into simple categories (each status is in one group only). */
    public const BOOKING_GROUPS = [
        'going' => ['confirmed', 'reschedule_requested', 'cancellation_requested', 'rescheduled'],
        'completed' => ['completed'],
        'waiting' => ['pending_downpayment'],
        'cancelled' => ['cancelled', 'cancelled_by_camp', 'cancelled_by_guest'],
        'no_show' => ['no_show'],
    ];

    /** Same thing for batch statuses. */
    public const BATCH_GROUPS = [
        'cancelled' => ['cancelled', 'cancelled_by_camp'],
        'completed' => ['completed'],
    ];

    public static function bookingGroup(?string $status): string
    {
        foreach (self::BOOKING_GROUPS as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }

        return 'going';
    }

    /** Simple booking status text used in the report, print page and exports. */
    public static function bookingStatusLabel(?string $status): string
    {
        return match ($status) {
            'reschedule_requested' => 'Asked to reschedule',
            'cancellation_requested' => 'Asked to cancel',
            default => match (self::bookingGroup($status)) {
                'completed' => 'Finished trip',
                'waiting' => 'Waiting for downpayment',
                'cancelled' => 'Cancelled',
                'no_show' => 'No-show',
                default => 'Paid & going',
            },
        };
    }

    public static function batchStatusLabel(?string $status): string
    {
        return match ($status) {
            'completed' => 'Finished',
            'cancelled', 'cancelled_by_camp' => 'Cancelled',
            'full' => 'Full',
            'confirmed' => 'Confirmed',
            default => 'Open for booking',
        };
    }

    public static function swimLabel(?string $status): string
    {
        return match (self::swimGroup($status)) {
            'cannot_swim' => "Can't swim",
            'strong' => 'Strong swimmer',
            default => 'Can swim',
        };
    }

    /** Swimming ability groups (each participant is in one group only). */
    public static function swimGroup(?string $status): string
    {
        return match ($status) {
            'non_swimmer' => 'cannot_swim',
            'confident_swimmer', 'confident', 'advanced' => 'strong',
            default => 'can_swim',
        };
    }

    /**
     * Get the start and end dates from the preset or the custom range.
     */
    public function resolveDateRange(string $preset = 'this_month', ?string $customStart = null, ?string $customEnd = null): array
    {
        $now = Carbon::now('Asia/Manila');

        switch ($preset) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $priorStart = $start->copy()->subDay();
                $priorEnd = $end->copy()->subDay();
                $label = 'Today (' . $start->format('M d, Y') . ')';
                break;

            case 'last_7_days':
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
                $priorStart = $start->copy()->subDays(7);
                $priorEnd = $start->copy()->subSecond();
                $label = 'Last 7 Days';
                break;

            case 'last_30_days':
                $start = $now->copy()->subDays(29)->startOfDay();
                $end = $now->copy()->endOfDay();
                $priorStart = $start->copy()->subDays(30);
                $priorEnd = $start->copy()->subSecond();
                $label = 'Last 30 Days';
                break;

            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                $priorStart = $start->copy()->subMonth()->startOfMonth();
                $priorEnd = $priorStart->copy()->endOfMonth();
                $label = $start->format('F Y');
                break;

            case 'last_month':
                $start = $now->copy()->subMonth()->startOfMonth();
                $end = $start->copy()->endOfMonth();
                $priorStart = $start->copy()->subMonth()->startOfMonth();
                $priorEnd = $priorStart->copy()->endOfMonth();
                $label = $start->format('F Y');
                break;

            case 'this_quarter':
                $start = $now->copy()->firstOfQuarter()->startOfDay();
                $end = $now->copy()->lastOfQuarter()->endOfDay();
                $priorStart = $start->copy()->subQuarter()->firstOfQuarter()->startOfDay();
                $priorEnd = $priorStart->copy()->lastOfQuarter()->endOfDay();
                $label = 'Q' . $now->quarter . ' ' . $now->year;
                break;

            case 'year_to_date':
            case 'ytd':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfDay();
                $priorStart = $start->copy()->subYear()->startOfYear();
                $priorEnd = $priorStart->copy()->addDays($start->diffInDays($end))->endOfDay();
                $label = 'YTD (' . $now->year . ')';
                $preset = 'year_to_date';
                break;

            case 'last_year':
                $start = $now->copy()->subYear()->startOfYear();
                $end = $start->copy()->endOfYear();
                $priorStart = $start->copy()->subYear()->startOfYear();
                $priorEnd = $priorStart->copy()->endOfYear();
                $label = 'Last Year (' . $start->year . ')';
                break;

            case 'all_time':
                // All bookings and all batches (including future ones)
                $firstBooking = Booking::min('created_at');
                $firstBatch = Batch::min('start_date');
                $lastBatch = Batch::max('end_date');
                $earliest = collect([$firstBooking, $firstBatch])->filter()->map(fn ($d) => Carbon::parse($d, 'Asia/Manila'))->min();
                $start = $earliest ? $earliest->startOfDay() : $now->copy()->subYears(2)->startOfDay();
                $end = $lastBatch && Carbon::parse($lastBatch, 'Asia/Manila')->gt($now)
                    ? Carbon::parse($lastBatch, 'Asia/Manila')->endOfDay()
                    : $now->copy()->endOfDay();
                $priorStart = $start->copy()->subYears(2);
                $priorEnd = $start->copy()->subSecond();
                $label = 'All Time';
                break;

            case 'custom':
                $start = $customStart ? Carbon::parse($customStart, 'Asia/Manila')->startOfDay() : $now->copy()->subDays(30)->startOfDay();
                $end = $customEnd ? Carbon::parse($customEnd, 'Asia/Manila')->endOfDay() : $now->copy()->endOfDay();
                $diffDays = max(1, $start->diffInDays($end));
                $priorStart = $start->copy()->subDays($diffDays)->startOfDay();
                $priorEnd = $start->copy()->subSecond();
                $label = $start->format('M d, Y') . ' - ' . $end->format('M d, Y');
                break;

            default:
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                $priorStart = $start->copy()->subMonth()->startOfMonth();
                $priorEnd = $priorStart->copy()->endOfMonth();
                $label = $start->format('F Y');
                $preset = 'this_month';
                break;
        }

        return [
            'preset' => $preset,
            'start' => $start,
            'end' => $end,
            'prior_start' => $priorStart,
            'prior_end' => $priorEnd,
            'label' => $label,
        ];
    }

    /**
     * Build all the report data.
     */
    public function getAnalyticsReport(array $range, bool $isOwner = true): array
    {
        $start = $range['start'];
        $end = $range['end'];
        $priorStart = $range['prior_start'];
        $priorEnd = $range['prior_end'];

        $financials = $isOwner ? $this->getFinancialMetrics($start, $end, $priorStart, $priorEnd) : [];
        $bookings = $this->getBookingMetrics($start, $end, $priorStart, $priorEnd);
        $operations = $this->getOperationsMetrics($start, $end, $priorStart, $priorEnd);
        $coaches = $this->getCoachMetrics($start, $end);
        $weather = $this->getWeatherMetrics($start, $end);

        return [
            'range' => $range,
            'is_owner' => $isOwner,
            'financials' => $financials,
            'bookings' => $bookings,
            'operations' => $operations,
            'coaches' => $coaches,
            'weather' => $weather,
        ];
    }

    /**
     * Money numbers (owner only).
     */
    protected function getFinancialMetrics(Carbon $start, Carbon $end, Carbon $priorStart, Carbon $priorEnd): array
    {
        // Payment totals for this period and the one before, in one query
        $paid = "status IN ('completed', 'paid')";
        $paymentTotals = Payment::query()->selectRaw("
                COALESCE(SUM(CASE WHEN {$paid} AND created_at BETWEEN ? AND ? THEN amount END), 0) AS gross,
                COALESCE(SUM(CASE WHEN {$paid} AND created_at BETWEEN ? AND ? AND payment_type = 'downpayment' THEN amount END), 0) AS downpayment,
                COALESCE(SUM(CASE WHEN {$paid} AND created_at BETWEEN ? AND ? AND payment_type IN ('balance_settlement', 'full') THEN amount END), 0) AS balance,
                COALESCE(SUM(CASE WHEN status = 'refunded' AND updated_at BETWEEN ? AND ? THEN amount_refunded END), 0) AS refunded,
                COALESCE(SUM(CASE WHEN {$paid} AND created_at BETWEEN ? AND ? THEN amount END), 0) AS prior_gross,
                COALESCE(SUM(CASE WHEN status = 'refunded' AND updated_at BETWEEN ? AND ? THEN amount_refunded END), 0) AS prior_refunded
            ", [$start, $end, $start, $end, $start, $end, $start, $end, $priorStart, $priorEnd, $priorStart, $priorEnd])->first();

        $grossRevenue = (float) $paymentTotals->gross;
        $downpaymentRevenue = (float) $paymentTotals->downpayment;
        $balanceRevenue = (float) $paymentTotals->balance;
        $refundsProcessed = (float) $paymentTotals->refunded;

        if ($refundsProcessed === 0.0) {
            $refundsProcessed = (float) CancellationRequest::where('status', 'approved')
                ->whereBetween('reviewed_at', [$start, $end])
                ->sum('calculated_refund_amount');
        }

        $netRevenue = max(0, $grossRevenue - $refundsProcessed);

        // Still to collect = price minus what was paid, for bookings that are still active.
        // (balance_amount isn't cleared when the participant pays in full, so we can't just add it up.)
        $outstandingReceivables = (float) Booking::whereIn('status', array_merge(self::BOOKING_GROUPS['going'], self::BOOKING_GROUPS['completed']))
            ->whereBetween('created_at', [$start, $end])
            ->withSum(['payments as paid_sum' => fn ($q) => $q->whereIn('status', ['completed', 'paid'])], 'amount')
            ->get(['id', 'total_amount'])
            ->sum(fn ($b) => max(0, (float) $b->total_amount - (float) $b->paid_sum));

        // Previous period, for the change %
        $priorGross = (float) $paymentTotals->prior_gross;
        $priorNet = max(0, $priorGross - (float) $paymentTotals->prior_refunded);

        $revenueDelta = $priorNet > 0 ? round((($netRevenue - $priorNet) / $priorNet) * 100, 1) : 0;

        // Income per package
        $packages = [
            'discovery' => [
                'name' => 'Discovery',
                'color' => '#780000',
                'bg_color' => 'bg-[#780000]',
                'dot_class' => 'bg-[#780000]',
                'text_color' => 'text-[#780000]',
            ],
            'fundive' => [
                'name' => 'Fundive',
                'color' => '#A82020',
                'bg_color' => 'bg-[#A82020]',
                'dot_class' => 'bg-[#A82020]',
                'text_color' => 'text-[#A82020]',
            ],
            'refinement' => [
                'name' => 'Refinement',
                'color' => '#D45D5D',
                'bg_color' => 'bg-[#D45D5D]',
                'dot_class' => 'bg-[#D45D5D]',
                'text_color' => 'text-[#D45D5D]',
            ],
        ];

        // Income per class (payments in this period)
        $revenueByClass = Payment::join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->whereIn('payments.status', ['completed', 'paid'])
            ->whereBetween('payments.created_at', [$start, $end])
            ->selectRaw('bookings.class_type, SUM(payments.amount) AS s')
            ->groupBy('bookings.class_type')
            ->pluck('s', 'class_type');

        // Bookings and participants per class, with carpool / boat dive counts
        $addonColumns = "COUNT(*) AS c,
                SUM(CASE WHEN bookings.pickup_option = 'carpool' THEN 1 ELSE 0 END) AS carpool,
                SUM(CASE WHEN bookings.boat_dive THEN 1 ELSE 0 END) AS boat";
        $bookingStats = Booking::where('status', '!=', 'pending_downpayment')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("class_type, {$addonColumns}")
            ->groupBy('class_type')
            ->get()
            ->keyBy('class_type');
        $paxStats = BookingParticipant::join('bookings', 'bookings.id', '=', 'booking_participants.booking_id')
            ->where('bookings.status', '!=', 'pending_downpayment')
            ->whereBetween('bookings.created_at', [$start, $end])
            ->selectRaw("bookings.class_type, {$addonColumns}")
            ->groupBy('bookings.class_type')
            ->get()
            ->keyBy('class_type');

        $packageRevenue = [];
        foreach ($packages as $type => $meta) {
            $rev = (float) ($revenueByClass[$type] ?? 0);
            $bookingsCount = (int) ($bookingStats[$type]->c ?? 0);
            $paxCount = (int) ($paxStats[$type]->c ?? 0);

            $share = $grossRevenue > 0 ? round(($rev / $grossRevenue) * 100, 1) : 0;

            $packageRevenue[$type] = [
                'name' => $meta['name'],
                'color' => $meta['color'],
                'bg_color' => $meta['bg_color'],
                'dot_class' => $meta['dot_class'],
                'text_color' => $meta['text_color'],
                'revenue' => $rev,
                'bookings_count' => $bookingsCount,
                'pax_count' => $paxCount,
                'share' => $share,
                'share_percentage' => $share,
            ];
        }

        // Add-ons (carpool and boat dive)
        $carpoolFee = (float) (app(\App\Services\SystemSettingService::class)->get('addons.carpool_fee_per_head', app(\App\Services\SystemSettingService::class)->get('addons.carpool_roundtrip_fee', 1200)) ?? 1200);
        $boatFee = (float) (app(\App\Services\SystemSettingService::class)->get('addons.boat_dive_fee_per_head', app(\App\Services\SystemSettingService::class)->get('addons.boat_dive_fee', 600)) ?? 600);

        $carpoolBookings = (int) $bookingStats->sum('carpool');
        $carpoolPax = (int) $paxStats->sum('carpool');
        $carpoolRevenue = $carpoolPax * $carpoolFee;

        $boatDiveBookings = (int) $bookingStats->sum('boat');
        $boatDivePax = (int) $paxStats->sum('boat');
        $boatDiveRevenue = $boatDivePax * $boatFee;

        // Extra income from pricing rules (one query)
        $adjustmentTotals = BookingPriceAdjustment::whereBetween('created_at', [$start, $end])->selectRaw('
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN adjustment_amount > 0 THEN adjustment_amount END), 0) AS positive,
                COALESCE(SUM(CASE WHEN adjustment_amount < 0 THEN adjustment_amount END), 0) AS negative
            ')->first();
        $positiveYield = (float) $adjustmentTotals->positive;
        $discountsGiven = (float) abs($adjustmentTotals->negative);
        $netDynamicLift = $positiveYield - $discountsGiven;

        // Average income per participant and per booking
        $totalPax = (int) $paxStats->sum('c');
        $totalBookings = (int) $bookingStats->sum('c');

        $arpd = $totalPax > 0 ? round($netRevenue / $totalPax, 2) : 0;
        $arpb = $totalBookings > 0 ? round($netRevenue / $totalBookings, 2) : 0;

        return [
            'gross_revenue' => $grossRevenue,
            'downpayment_revenue' => $downpaymentRevenue,
            'balance_revenue' => $balanceRevenue,
            'refunds_processed' => $refundsProcessed,
            'net_revenue' => $netRevenue,
            'outstanding_receivables' => $outstandingReceivables,
            'revenue_delta' => $revenueDelta,
            'packages' => $packageRevenue,
            'carpool' => [
                'bookings_count' => $carpoolBookings,
                'pax_count' => $carpoolPax,
                'estimated_revenue' => $carpoolRevenue,
            ],
            'boat_dive' => [
                'bookings_count' => $boatDiveBookings,
                'pax_count' => $boatDivePax,
                'estimated_revenue' => $boatDiveRevenue,
            ],
            'dynamic_pricing' => [
                'positive_yield' => $positiveYield,
                'discounts_given' => $discountsGiven,
                'net_lift' => $netDynamicLift,
                'adjustments_count' => (int) $adjustmentTotals->total,
            ],
            'arpd' => $arpd,
            'arpb' => $arpb,
        ];
    }

    /**
     * Booking numbers.
     */
    protected function getBookingMetrics(Carbon $start, Carbon $end, Carbon $priorStart, Carbon $priorEnd): array
    {
        $bookingsQuery = Booking::whereBetween('created_at', [$start, $end]);

        // Count per status, then put them in groups so the groups add up to the total
        $statusCounts = (clone $bookingsQuery)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');
        $statusGroups = array_fill_keys(array_keys(self::BOOKING_GROUPS), 0);
        foreach ($statusCounts as $status => $c) {
            $statusGroups[self::bookingGroup($status)] += (int) $c;
        }

        $totalBookings = array_sum($statusGroups);
        $pendingBookings = $statusGroups['waiting'];
        $cancelledBookings = $statusGroups['cancelled'];
        // "Paid" = got past the downpayment step (going, done, no-show, or cancelled after paying)
        $paidBookings = $totalBookings - $pendingBookings;
        $confirmedBookings = $statusGroups['going'] + $statusGroups['completed'];

        $paxByStatus = BookingParticipant::join('bookings', 'bookings.id', '=', 'booking_participants.booking_id')
            ->whereBetween('bookings.created_at', [$start, $end])
            ->selectRaw('bookings.status, COUNT(*) AS c')
            ->groupBy('bookings.status')
            ->pluck('c', 'status');
        $totalParticipants = (int) $paxByStatus->sum();
        $confirmedParticipants = (int) $paxByStatus->filter(fn ($c, $status) => in_array(self::bookingGroup($status), ['going', 'completed'], true))->sum();

        // Previous period count, for the change %
        $priorBookings = Booking::whereBetween('created_at', [$priorStart, $priorEnd])->count();
        $bookingDelta = $priorBookings > 0 ? round((($totalBookings - $priorBookings) / $priorBookings) * 100, 1) : 0;

        // Group sizes
        $groupSizeDistribution = [
            'solo' => 0,      // 1 participant
            'duo' => 0,       // 2 participants
            'small_group' => 0, // 3-4 participants
            'large_group' => 0, // 5+ participants
        ];

        $allPeriodBookings = Booking::whereBetween('created_at', [$start, $end])
            ->withCount('participants')
            ->get();

        foreach ($allPeriodBookings as $b) {
            $cnt = $b->participants_count;
            if ($cnt <= 1) {
                $groupSizeDistribution['solo']++;
            } elseif ($cnt === 2) {
                $groupSizeDistribution['duo']++;
            } elseif ($cnt <= 4) {
                $groupSizeDistribution['small_group']++;
            } else {
                $groupSizeDistribution['large_group']++;
            }
        }

        // Swimming ability in the Discovery class
        $discoveryParticipants = BookingParticipant::whereHas('booking', function ($q) use ($start, $end) {
            $q->where('class_type', 'discovery')->whereBetween('created_at', [$start, $end]);
        })->get();

        // Each participant is in one group only, so the groups add up to the total
        $swimGroups = $discoveryParticipants->countBy(fn ($p) => self::swimGroup($p->swimmer_status));
        $swimmerAbility = [
            'cannot_swim' => (int) ($swimGroups['cannot_swim'] ?? 0),
            'can_swim' => (int) ($swimGroups['can_swim'] ?? 0),
            'strong' => (int) ($swimGroups['strong'] ?? 0),
            'total' => $discoveryParticipants->count(),
        ];

        // Days between booking and dive
        $leadTimes = [
            'under_3_days' => 0,
            '4_to_7_days' => 0,
            '8_to_14_days' => 0,
            '15_to_30_days' => 0,
            'over_30_days' => 0,
        ];

        foreach ($allPeriodBookings as $b) {
            if ($b->start_date && $b->created_at) {
                $diff = $b->created_at->diffInDays($b->start_date, false);
                if ($diff < 4) {
                    $leadTimes['under_3_days']++;
                } elseif ($diff <= 7) {
                    $leadTimes['4_to_7_days']++;
                } elseif ($diff <= 14) {
                    $leadTimes['8_to_14_days']++;
                } elseif ($diff <= 30) {
                    $leadTimes['15_to_30_days']++;
                } else {
                    $leadTimes['over_30_days']++;
                }
            }
        }

        // Reschedule and cancellation requests
        $rescheduleCount = RescheduleRequest::whereBetween('created_at', [$start, $end])->count();
        $cancellationCount = CancellationRequest::whereBetween('created_at', [$start, $end])->count();
        $conversionRate = $totalBookings > 0 ? round(($paidBookings / $totalBookings) * 100, 1) : 0;
        $cancellationRate = $totalBookings > 0 ? round(($cancelledBookings / $totalBookings) * 100, 1) : 0;

        return [
            'total_bookings' => $totalBookings,
            'status_groups' => $statusGroups,
            'paid_bookings' => $paidBookings,
            'confirmed_bookings' => $confirmedBookings,
            'pending_bookings' => $pendingBookings,
            'cancelled_bookings' => $cancelledBookings,
            'total_participants' => $totalParticipants,
            'confirmed_participants' => $confirmedParticipants,
            'booking_delta' => $bookingDelta,
            'conversion_rate' => $conversionRate,
            'cancellation_rate' => $cancellationRate,
            'group_sizes' => $groupSizeDistribution,
            'swimmer_ability' => $swimmerAbility,
            'lead_times' => $leadTimes,
            'reschedule_count' => $rescheduleCount,
            'cancellation_count' => $cancellationCount,
            'bookings_list' => $allPeriodBookings->loadMissing(['participants', 'batch'])->sortByDesc('created_at')->values(),
        ];
    }

    /**
     * Batch and capacity numbers.
     */
    protected function getOperationsMetrics(Carbon $start, Carbon $end, Carbon $priorStart, Carbon $priorEnd): array
    {
        $batches = Batch::whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
            ->with(['bookings' => fn($q) => $q->where('status', '!=', 'pending_downpayment')->with('participants'), 'participantAssignments.coach', 'activeParticipantAssignments.coach', 'riskAssessment'])
            ->get();
        Batch::preloadAssignedCoaches($batches);

        $totalBatches = $batches->count();
        $completedBatches = $batches->whereIn('status', self::BATCH_GROUPS['completed'])->count();
        $cancelledBatches = $batches->whereIn('status', self::BATCH_GROUPS['cancelled'])->count();
        // Everything else (open, confirmed, full, ...) is still active
        $activeBatches = $totalBatches - $completedBatches - $cancelledBatches;

        // Fill rate only counts batches that ran or will run
        $runningBatches = $batches->whereNotIn('status', self::BATCH_GROUPS['cancelled']);
        $totalCapacitySlots = $runningBatches->sum(fn ($b) => $b->computed_capacity);
        $totalBookedPax = $runningBatches->sum(fn ($b) => $b->total_participants_count);
        $avgOccupancy = $totalCapacitySlots > 0 ? round(($totalBookedPax / $totalCapacitySlots) * 100, 1) : 0;

        // Weekend vs weekday
        $weekendBatches = $runningBatches->filter(fn($b) => in_array(Carbon::parse($b->start_date)->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY]));
        $weekdayBatches = $runningBatches->filter(fn($b) => !in_array(Carbon::parse($b->start_date)->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY]));

        $weekendPax = $weekendBatches->sum(fn($b) => $b->total_participants_count);
        $weekendCapacity = $weekendBatches->sum(fn ($b) => $b->computed_capacity);
        $weekendOccupancy = $weekendCapacity > 0 ? round(($weekendPax / $weekendCapacity) * 100, 1) : 0;

        $weekdayPax = $weekdayBatches->sum(fn($b) => $b->total_participants_count);
        $weekdayCapacity = $weekdayBatches->sum(fn ($b) => $b->computed_capacity);
        $weekdayOccupancy = $weekdayCapacity > 0 ? round(($weekdayPax / $weekdayCapacity) * 100, 1) : 0;

        // Batches that are 90% full or more
        $fullCapacityBatches = $runningBatches->filter(fn($b) => ($b->occupancy_percentage ?? 0) >= 90)->count();

        // Coach to participant ratio
        $coachRatio = (int) (app(\App\Services\SystemSettingService::class)->get('camp_operations.coach_student_ratio', 4) ?? 4);
        $compliantBatches = $runningBatches->filter(function ($b) use ($coachRatio) {
            $pax = $b->total_participants_count;
            if ($pax === 0) return true;
            $requiredCoaches = (int) ceil($pax / $coachRatio);
            return $b->assigned_coaches_count >= $requiredCoaches;
        })->count();

        $runningCount = $runningBatches->count();
        $safetyComplianceRate = $runningCount > 0 ? round(($compliantBatches / $runningCount) * 100, 1) : 100;

        return [
            'total_batches' => $totalBatches,
            'completed_batches' => $completedBatches,
            'active_batches' => $activeBatches,
            'cancelled_batches' => $cancelledBatches,
            'total_capacity_slots' => $totalCapacitySlots,
            'total_booked_pax' => $totalBookedPax,
            'avg_occupancy' => $avgOccupancy,
            'weekend_occupancy' => $weekendOccupancy,
            'weekday_occupancy' => $weekdayOccupancy,
            'full_capacity_batches' => $fullCapacityBatches,
            'safety_compliance_rate' => $safetyComplianceRate,
            'compliant_batches_count' => $compliantBatches,
            'running_batches_count' => $runningCount,
            'coach_ratio' => $coachRatio,
            'batches_list' => $batches->sortBy('start_date')->values(),
        ];
    }

    /**
     * Coach numbers.
     */
    protected function getCoachMetrics(Carbon $start, Carbon $end): array
    {
        $coaches = User::where('role', 'coach')->get();
        $batchDays = fn ($b) => $b->end_date ? $b->start_date->diffInDays($b->end_date) + 1 : 1;
        $batches = Batch::whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
            ->with(['activeParticipantAssignments.coach'])
            ->get();
        Batch::preloadAssignedCoaches($batches);

        $coachData = [];
        $totalAssignments = 0;
        $releasesByCoach = Schema::hasTable('assignment_release_requests')
            ? DB::table('assignment_release_requests')
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('coach_id, COUNT(*) AS c')
                ->groupBy('coach_id')
                ->pluck('c', 'coach_id')
            : collect();

        foreach ($coaches as $coach) {
            // Batches in this period where this coach is assigned (not counting cancelled ones)
            $coachBatches = $batches->filter(function ($b) use ($coach) {
                return !in_array($b->status, self::BATCH_GROUPS['cancelled'], true)
                    && $b->assigned_coaches->pluck('id')->contains($coach->id);
            });
            $assignedBatchesCount = $coachBatches->count();

            $totalAssignments += $assignedBatchesCount;

            $releases = (int) ($releasesByCoach[$coach->id] ?? 0);

            // Archived coaches only show if they worked in this period
            if ($coach->status === 'archived' && $assignedBatchesCount === 0 && $releases === 0) {
                continue;
            }

            $coachData[] = [
                'id' => $coach->id,
                'name' => $coach->name,
                'email' => $coach->email,
                'status' => $coach->status,
                'assignments_count' => $assignedBatchesCount,
                'releases_count' => $releases,
                'estimated_dive_days' => (int) $coachBatches->sum($batchDays),
            ];
        }

        // Busiest coaches first
        usort($coachData, fn($a, $b) => $b['assignments_count'] <=> $a['assignments_count']);

        return [
            'total_active_coaches' => $coaches->where('status', 'active')->count(),
            'total_assignments_period' => $totalAssignments,
            'total_dive_days' => (int) collect($coachData)->sum('estimated_dive_days'),
            'coaches' => $coachData,
        ];
    }

    /**
     * Weather cancellations and safety history.
     */
    protected function getWeatherMetrics(Carbon $start, Carbon $end): array
    {
        $batches = Batch::whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
            ->with('riskAssessment')
            ->get();

        $classifications = [
            'very_safe' => 0,
            'safe' => 0,
            'moderate' => 0,
            'high_risk' => 0,
            'critical_risk' => 0,
        ];

        foreach ($batches as $b) {
            $raw = $b->risk_classification ?? $b->riskAssessment?->overall_risk_rating;
            if (!$raw || $raw === 'Not Available') {
                continue; // not assessed yet
            }
            $rating = strtolower(str_replace([' ', '-'], '_', $raw));
            $rating = match (true) {
                str_contains($rating, 'critical') => 'critical_risk',
                str_contains($rating, 'high') => 'high_risk',
                str_contains($rating, 'moderate') => 'moderate',
                str_contains($rating, 'very') => 'very_safe',
                default => 'safe',
            };
            $classifications[$rating]++;
        }

        $totalEvaluated = array_sum($classifications);
        $safePercentage = $totalEvaluated > 0 ? round((($classifications['very_safe'] + $classifications['safe']) / $totalEvaluated) * 100, 1) : 100;
        $highRiskPercentage = $totalEvaluated > 0 ? round((($classifications['high_risk'] + $classifications['critical_risk']) / $totalEvaluated) * 100, 1) : 0;

        return [
            'total_evaluated_batches' => $totalEvaluated,
            'safe_percentage' => $safePercentage,
            'high_risk_percentage' => $highRiskPercentage,
            'classifications' => $classifications,
        ];
    }
}
