<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssignmentReleaseRequest;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingPriceAdjustment;
use App\Models\CancellationRequest;
use App\Models\Payment;
use App\Models\PricingRule;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the Admin & Owner persona-driven operational and executive dashboard.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        $isOwner = ($user->role === 'owner');


        $now = Carbon::now('Asia/Manila');
        $today = $now->copy()->startOfDay();

        // =========================================================================
        // 1. ACTION REQUIRED INBOX (OPERATIONAL BOTTLE-NECK PREVENTION)
        // =========================================================================
        $pendingReschedules = RescheduleRequest::where('status', 'pending')
            ->with(['booking.participants'])
            ->latest()
            ->get();

        $pendingCancellations = CancellationRequest::where('status', 'pending')
            ->with(['booking.participants'])
            ->latest()
            ->get();

        $pendingReleases = AssignmentReleaseRequest::where('status', 'pending')
            ->with(['coach', 'batch'])
            ->latest()
            ->get();

        $pendingRefunds = RefundRequest::where('status', 'pending')
            ->with(['booking', 'payment'])
            ->latest()
            ->get();

        $coachRatio = (int) (app(\App\Services\SystemSettingService::class)->get('camp_operations.coach_student_ratio', 4) ?? 4);

        // Upcoming active batches: loaded once, reused by the inbox, runway and occupancy stats
        $allUpcomingBatches = Batch::where('start_date', '>=', $today)
            ->whereIn('status', ['confirmed', 'open'])
            ->orderBy('start_date', 'asc')
            ->with([
                'bookings' => fn($q) => $q->where('status', '!=', 'pending_downpayment')->with('participants'),
                'coachAssignments.coach',
                'activeParticipantAssignments.coach',
                'riskAssessment',
            ])
            ->get();
        Batch::preloadAssignedCoaches($allUpcomingBatches);

        $understaffedBatches = $allUpcomingBatches
            ->filter(fn($b) => $b->is_coach_pending || ($b->total_participants_count > 0 && $b->assigned_coaches_count < ceil($b->total_participants_count / $coachRatio)))
            ->values();

        $weatherAlerts = $allUpcomingBatches
            ->filter(fn($b) => in_array(strtolower($b->risk_classification ?? ''), ['high_risk', 'critical_risk']) || in_array(strtolower($b->riskAssessment?->overall_risk_rating ?? ''), ['high_risk', 'critical_risk']))
            ->values();

        $totalActionCount = $pendingReschedules->count() 
            + $pendingCancellations->count() 
            + $pendingReleases->count() 
            + $pendingRefunds->count() 
            + $understaffedBatches->count() 
            + $weatherAlerts->count();

        $actionInbox = [
            'reschedules' => $pendingReschedules,
            'cancellations' => $pendingCancellations,
            'releases' => $pendingReleases,
            'refunds' => $pendingRefunds,
            'understaffed' => $understaffedBatches,
            'weather_alerts' => $weatherAlerts,
            'total_count' => $totalActionCount,
        ];

        // =========================================================================
        // 2. BATCH RUNWAY (NEXT 4 UPCOMING TRIPS)
        // =========================================================================
        $upcomingBatches = $allUpcomingBatches->take(4)->values();

        // =========================================================================
        // 3. OPERATIONAL HEALTH STATS
        // =========================================================================
        $activeDiversMonth = BookingParticipant::whereHas('booking', function ($q) use ($today) {
            $q->where('status', '!=', 'pending_downpayment')
              ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled'])
              ->whereDate('start_date', '>=', $today->copy()->startOfMonth())
              ->whereDate('start_date', '<=', $today->copy()->endOfMonth());
        })->count();

        $avgOccupancy = $allUpcomingBatches->count() > 0
            ? (int) round($allUpcomingBatches->avg(fn($b) => $b->occupancy_percentage ?? 0))
            : 0;

        $activeCoachesCount = User::where('role', 'coach')->where('status', 'active')->count();
        $unmatchedStudentsCount = BookingParticipant::whereHas('booking', fn($q) => $q->where('status', 'confirmed'))
            ->whereDoesntHave('assignment')
            ->count();

        $operationalStats = [
            'active_divers_month' => $activeDiversMonth,
            'avg_occupancy' => $avgOccupancy,
            'active_coaches_count' => $activeCoachesCount,
            'unmatched_students_count' => $unmatchedStudentsCount,
            'total_active_batches' => $allUpcomingBatches->count(),
        ];

        // Recent Confirmed Bookings Feed
        $recentBookings = Booking::where('status', '!=', 'pending_downpayment')
            ->with(['participants', 'payments', 'batch'])
            ->latest()
            ->take(6)
            ->get();

        // =========================================================================
        // 4. OWNER EXECUTIVE & FINANCIAL ANALYTICS
        // =========================================================================
        $paymentTotals = Payment::query()->selectRaw("
                COALESCE(SUM(CASE WHEN status IN ('completed', 'paid') THEN amount END), 0) AS gross,
                COALESCE(SUM(CASE WHEN status IN ('completed', 'paid') AND payment_type = 'downpayment' THEN amount END), 0) AS downpayment,
                COALESCE(SUM(CASE WHEN status IN ('completed', 'paid') AND payment_type IN ('balance_settlement', 'full') THEN amount END), 0) AS balance,
                COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount_refunded END), 0) AS refunded_status,
                COALESCE(SUM(amount_refunded), 0) AS refunded_all
            ")->first();
        $grossRevenue = (float) $paymentTotals->gross;
        $downpaymentRevenue = (float) $paymentTotals->downpayment;
        $balanceRevenue = (float) $paymentTotals->balance;
        $outstandingBalances = (float) Booking::where('status', 'confirmed')->sum('balance_amount');
        $refundsProcessed = (float) $paymentTotals->refunded_status
            ?: (float) $paymentTotals->refunded_all
            ?: (float) CancellationRequest::where('status', 'approved')->sum('calculated_refund_amount');
        $netRevenue = max(0, $grossRevenue - $refundsProcessed);

        $financials = [
            'gross_revenue' => $grossRevenue,
            'downpayment_revenue' => $downpaymentRevenue,
            'balance_revenue' => $balanceRevenue,
            'outstanding_balances' => $outstandingBalances,
            'refunds_processed' => $refundsProcessed,
            'net_revenue' => $netRevenue,
        ];

        // Class Package Mix & Revenue Breakdown (Cohesive shades of #780000)
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
        $packageAnalytics = [];
        $bookingCountsByClass = Booking::where('status', '!=', 'pending_downpayment')
            ->selectRaw('class_type, COUNT(*) AS c')
            ->groupBy('class_type')
            ->pluck('c', 'class_type');
        $paxByClass = BookingParticipant::join('bookings', 'bookings.id', '=', 'booking_participants.booking_id')
            ->where('bookings.status', '!=', 'pending_downpayment')
            ->selectRaw('bookings.class_type, COUNT(*) AS c')
            ->groupBy('bookings.class_type')
            ->pluck('c', 'class_type');
        $revenueByClass = Payment::join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->whereIn('payments.status', ['completed', 'paid'])
            ->selectRaw('bookings.class_type, SUM(payments.amount) AS s')
            ->groupBy('bookings.class_type')
            ->pluck('s', 'class_type');
        $totalBookingsCount = max(1, (int) $bookingCountsByClass->sum());

        foreach ($packages as $key => $pkg) {
            $count = (int) ($bookingCountsByClass[$key] ?? 0);
            $paxCount = (int) ($paxByClass[$key] ?? 0);
            $rev = (float) ($revenueByClass[$key] ?? 0);

            $packageAnalytics[$key] = [
                'name' => $pkg['name'],
                'color' => $pkg['color'],
                'bg_color' => $pkg['bg_color'],
                'dot_class' => $pkg['dot_class'],
                'text_color' => $pkg['text_color'],
                'bookings_count' => $count,
                'pax_count' => $paxCount,
                'revenue' => $rev,
                'share_percentage' => round(($count / $totalBookingsCount) * 100, 1),
            ];
        }

        // Dynamic Pricing Analytics
        $activeRulesCount = PricingRule::where('status', 'active')->count();
        $adjustmentTotals = BookingPriceAdjustment::query()->selectRaw('
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN adjustment_amount > 0 THEN adjustment_amount END), 0) AS positive,
                COALESCE(SUM(CASE WHEN adjustment_amount < 0 THEN adjustment_amount END), 0) AS negative
            ')->first();
        $totalAdjustments = (int) $adjustmentTotals->total;
        $positiveYield = (float) $adjustmentTotals->positive;
        $discountGiven = (float) abs($adjustmentTotals->negative);
        $netDynamicLift = $positiveYield - $discountGiven;

        $dynamicPricingStats = [
            'active_rules' => $activeRulesCount,
            'total_adjustments' => $totalAdjustments,
            'positive_yield' => $positiveYield,
            'discount_given' => $discountGiven,
            'net_lift' => $netDynamicLift,
            'recent_adjustments' => BookingPriceAdjustment::with('booking')->latest()->take(4)->get(),
        ];

        // Governance & Audit Logs
        $recentAuditLogs = AuditLog::with('user')->latest('created_at')->take(6)->get();

        // AI Demand & Revenue Forecast
        $forecastData = app(\App\Services\DemandForecastService::class)->getForecastData();

        return view('admin.dashboard', compact(
            'user',
            'isOwner',
            'actionInbox',
            'upcomingBatches',
            'operationalStats',
            'recentBookings',
            'financials',
            'packageAnalytics',
            'dynamicPricingStats',
            'recentAuditLogs',
            'forecastData'
        ));
    }
}
