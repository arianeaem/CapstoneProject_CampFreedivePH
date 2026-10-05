<?php

namespace App\Services\Analytics;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingPriceAdjustment;
use App\Models\CancellationRequest;
use App\Models\Coach;
use App\Models\CoachAssignment;
use App\Models\Payment;
use App\Models\PricingRule;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsService
{
    /**
     * Resolve start and end Carbon dates from request preset or custom range.
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
                $firstBooking = Booking::oldest('created_at')->first();
                $start = $firstBooking ? $firstBooking->created_at->startOfDay() : $now->copy()->subYears(2)->startOfDay();
                $end = $now->copy()->endOfDay();
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
     * Compute full multi-dimensional analytics report data.
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
     * Financial & Revenue Metrics (Owner only).
     */
    protected function getFinancialMetrics(Carbon $start, Carbon $end, Carbon $priorStart, Carbon $priorEnd): array
    {
        // Current & prior period payment totals in a single query
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

        // Outstanding Receivables in Period
        $outstandingReceivables = (float) Booking::where('status', 'confirmed')
            ->whereBetween('created_at', [$start, $end])
            ->sum('balance_amount');

        // Prior Period for Delta calculation
        $priorGross = (float) $paymentTotals->prior_gross;
        $priorNet = max(0, $priorGross - (float) $paymentTotals->prior_refunded);

        $revenueDelta = $priorNet > 0 ? round((($netRevenue - $priorNet) / $priorNet) * 100, 1) : 0;

        // Package Revenue Breakdown
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

        // Revenue per class type (payments in period, joined to their booking)
        $revenueByClass = Payment::join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->whereIn('payments.status', ['completed', 'paid'])
            ->whereBetween('payments.created_at', [$start, $end])
            ->selectRaw('bookings.class_type, SUM(payments.amount) AS s')
            ->groupBy('bookings.class_type')
            ->pluck('s', 'class_type');

        // Bookings and divers per class type, incl. carpool / boat-dive add-on counts
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

        // Add-ons Breakdown (Carpool & Boat Dive)
        $carpoolFee = (float) (app(\App\Services\SystemSettingService::class)->get('addons.carpool_fee_per_head', app(\App\Services\SystemSettingService::class)->get('addons.carpool_roundtrip_fee', 1200)) ?? 1200);
        $boatFee = (float) (app(\App\Services\SystemSettingService::class)->get('addons.boat_dive_fee_per_head', app(\App\Services\SystemSettingService::class)->get('addons.boat_dive_fee', 600)) ?? 600);

        $carpoolBookings = (int) $bookingStats->sum('carpool');
        $carpoolPax = (int) $paxStats->sum('carpool');
        $carpoolRevenue = $carpoolPax * $carpoolFee;

        $boatDiveBookings = (int) $bookingStats->sum('boat');
        $boatDivePax = (int) $paxStats->sum('boat');
        $boatDiveRevenue = $boatDivePax * $boatFee;

        // Dynamic Pricing Lift (one aggregate query)
        $adjustmentTotals = BookingPriceAdjustment::whereBetween('created_at', [$start, $end])->selectRaw('
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN adjustment_amount > 0 THEN adjustment_amount END), 0) AS positive,
                COALESCE(SUM(CASE WHEN adjustment_amount < 0 THEN adjustment_amount END), 0) AS negative
            ')->first();
        $positiveYield = (float) $adjustmentTotals->positive;
        $discountsGiven = (float) abs($adjustmentTotals->negative);
        $netDynamicLift = $positiveYield - $discountsGiven;

        // Average Revenue Per Diver (ARPD) & Booking (ARPB)
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
     * Bookings, Cohorts & Demand Metrics.
     */
    protected function getBookingMetrics(Carbon $start, Carbon $end, Carbon $priorStart, Carbon $priorEnd): array
    {
        $bookingsQuery = Booking::whereBetween('created_at', [$start, $end]);

        $totalBookings = (clone $bookingsQuery)->count();
        $confirmedBookings = (clone $bookingsQuery)->where('status', 'confirmed')->count();
        $pendingBookings = (clone $bookingsQuery)->where('status', 'pending_downpayment')->count();
        $cancelledBookings = (clone $bookingsQuery)->whereIn('status', ['cancelled', 'cancelled_by_camp', 'cancelled_by_guest'])->count();

        $totalParticipants = BookingParticipant::whereHas('booking', fn($q) => $q->whereBetween('created_at', [$start, $end]))->count();
        $confirmedParticipants = BookingParticipant::whereHas('booking', fn($q) => $q->where('status', 'confirmed')->whereBetween('created_at', [$start, $end]))->count();

        // Prior period booking count for delta
        $priorBookings = Booking::whereBetween('created_at', [$priorStart, $priorEnd])->count();
        $bookingDelta = $priorBookings > 0 ? round((($totalBookings - $priorBookings) / $priorBookings) * 100, 1) : 0;

        // Group Size Distribution
        $groupSizeDistribution = [
            'solo' => 0,      // 1 diver
            'duo' => 0,       // 2 divers
            'small_group' => 0, // 3-4 divers
            'large_group' => 0, // 5+ divers
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

        // Swimmer Ability Breakdown in Discovery Class
        $discoveryParticipants = BookingParticipant::whereHas('booking', function ($q) use ($start, $end) {
            $q->where('class_type', 'discovery')->whereBetween('created_at', [$start, $end]);
        })->get();

        $swimmerAbility = [
            'non_swimmer' => $discoveryParticipants->where('swimmer_status', 'non_swimmer')->count(),
            'casual_swimmer' => $discoveryParticipants->where('swimmer_status', 'casual_swimmer')->count(),
            'confident_swimmer' => $discoveryParticipants->where('swimmer_status', 'confident_swimmer')->count(),
            'total' => $discoveryParticipants->count(),
        ];

        // Lead Time Analysis (Days between booking created_at and dive start_date)
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

        // Reschedule & Cancellation Request Stats
        $rescheduleCount = RescheduleRequest::whereBetween('created_at', [$start, $end])->count();
        $cancellationCount = CancellationRequest::whereBetween('created_at', [$start, $end])->count();
        $conversionRate = $totalBookings > 0 ? round(($confirmedBookings / $totalBookings) * 100, 1) : 0;
        $cancellationRate = $totalBookings > 0 ? round(($cancelledBookings / $totalBookings) * 100, 1) : 0;

        return [
            'total_bookings' => $totalBookings,
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
     * Batches, Runway & Capacity Utilization Metrics.
     */
    protected function getOperationsMetrics(Carbon $start, Carbon $end, Carbon $priorStart, Carbon $priorEnd): array
    {
        $batches = Batch::whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
            ->with(['bookings' => fn($q) => $q->where('status', '!=', 'pending_downpayment')->with('participants'), 'participantAssignments.coach', 'activeParticipantAssignments.coach', 'riskAssessment'])
            ->get();
        Batch::preloadAssignedCoaches($batches);

        $totalBatches = $batches->count();
        $completedBatches = $batches->where('status', 'completed')->count();
        $activeBatches = $batches->whereIn('status', ['confirmed', 'open'])->count();
        $cancelledBatches = $batches->where('status', 'cancelled')->count();

        $totalCapacitySlots = $batches->sum('max_capacity') ?: ($totalBatches * 20);
        $totalBookedPax = $batches->sum(fn($b) => $b->total_participants_count);
        $avgOccupancy = $totalCapacitySlots > 0 ? round(($totalBookedPax / $totalCapacitySlots) * 100, 1) : 0;

        // Weekend vs. Weekday Occupancy
        $weekendBatches = $batches->filter(fn($b) => in_array(Carbon::parse($b->start_date)->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY]));
        $weekdayBatches = $batches->filter(fn($b) => !in_array(Carbon::parse($b->start_date)->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY]));

        $weekendPax = $weekendBatches->sum(fn($b) => $b->total_participants_count);
        $weekendCapacity = $weekendBatches->sum('max_capacity') ?: ($weekendBatches->count() * 20);
        $weekendOccupancy = $weekendCapacity > 0 ? round(($weekendPax / $weekendCapacity) * 100, 1) : 0;

        $weekdayPax = $weekdayBatches->sum(fn($b) => $b->total_participants_count);
        $weekdayCapacity = $weekdayBatches->sum('max_capacity') ?: ($weekdayBatches->count() * 20);
        $weekdayOccupancy = $weekdayCapacity > 0 ? round(($weekdayPax / $weekdayCapacity) * 100, 1) : 0;

        // Batches reaching full capacity (>= 90%)
        $fullCapacityBatches = $batches->filter(fn($b) => ($b->occupancy_percentage ?? 0) >= 90)->count();

        // Safety Ratio Adherence
        $coachRatio = (int) (app(\App\Services\SystemSettingService::class)->get('camp_operations.coach_student_ratio', 4) ?? 4);
        $compliantBatches = $batches->filter(function ($b) use ($coachRatio) {
            $pax = $b->total_participants_count;
            if ($pax === 0) return true;
            $requiredCoaches = (int) ceil($pax / $coachRatio);
            return $b->assigned_coaches_count >= $requiredCoaches;
        })->count();

        $safetyComplianceRate = $totalBatches > 0 ? round(($compliantBatches / $totalBatches) * 100, 1) : 100;

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
            'batches_list' => $batches->sortBy('start_date')->values(),
        ];
    }

    /**
     * Coach Staffing, Assignment & Workload Metrics.
     */
    protected function getCoachMetrics(Carbon $start, Carbon $end): array
    {
        $coaches = User::where('role', 'coach')->get();
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
            // Count distinct batches in range where this coach is assigned
            $assignedBatchesCount = $batches->filter(function ($b) use ($coach) {
                return $b->assigned_coaches->pluck('id')->contains($coach->id);
            })->count();

            $totalAssignments += $assignedBatchesCount;

            $releases = (int) ($releasesByCoach[$coach->id] ?? 0);

            $coachData[] = [
                'id' => $coach->id,
                'name' => $coach->name,
                'email' => $coach->email,
                'status' => $coach->status,
                'assignments_count' => $assignedBatchesCount,
                'releases_count' => $releases,
                'estimated_dive_days' => $assignedBatchesCount * 2, // 2-day weekend camp format
            ];
        }

        // Sort coaches by highest workload
        usort($coachData, fn($a, $b) => $b['assignments_count'] <=> $a['assignments_count']);

        return [
            'total_active_coaches' => $coaches->where('status', 'active')->count(),
            'total_assignments_period' => $totalAssignments,
            'coaches' => $coachData,
        ];
    }

    /**
     * Marine Safety & Weather Disruption History.
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
            $rating = strtolower(str_replace(' ', '_', $b->risk_classification ?? $b->riskAssessment?->overall_risk_rating ?? 'safe'));
            if (isset($classifications[$rating])) {
                $classifications[$rating]++;
            } else {
                $classifications['safe']++;
            }
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
