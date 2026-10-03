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
        
        // Active view: 'operations' or 'executive' (Owners default to executive, admins always operations)
        $defaultView = $isOwner ? 'executive' : 'operations';
        $activeView = $request->input('view', $defaultView);
        if (!$isOwner) {
            $activeView = 'operations';
        }

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
        $understaffedBatches = Batch::where('start_date', '>=', $today)
            ->whereIn('status', ['confirmed', 'open'])
            ->with(['bookings' => fn($q) => $q->where('status', '!=', 'pending_downpayment')->with('participants'), 'coachAssignments.coach'])
            ->get()
            ->filter(fn($b) => $b->is_coach_pending || ($b->total_participants_count > 0 && $b->assigned_coaches_count < ceil($b->total_participants_count / $coachRatio)))
            ->values();

        $weatherAlerts = Batch::where('start_date', '>=', $today)
            ->whereIn('status', ['confirmed', 'open'])
            ->with('riskAssessment')
            ->get()
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
        $upcomingBatches = Batch::where('start_date', '>=', $today)
            ->whereIn('status', ['confirmed', 'open'])
            ->orderBy('start_date', 'asc')
            ->with([
                'bookings' => fn($q) => $q->where('status', '!=', 'pending_downpayment')->with('participants'),
                'coachAssignments.coach',
                'riskAssessment'
            ])
            ->take(4)
            ->get();

        // =========================================================================
        // 3. OPERATIONAL HEALTH STATS
        // =========================================================================
        $activeDiversMonth = BookingParticipant::whereHas('booking', function ($q) use ($today) {
            $q->where('status', '!=', 'pending_downpayment')
              ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled'])
              ->whereDate('start_date', '>=', $today->copy()->startOfMonth())
              ->whereDate('start_date', '<=', $today->copy()->endOfMonth());
        })->count();

        $allUpcomingBatches = Batch::where('start_date', '>=', $today)
            ->whereIn('status', ['confirmed', 'open'])
            ->with(['bookings' => fn($q) => $q->where('status', '!=', 'pending_downpayment')->with('participants')])
            ->get();

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
        $grossRevenue = (float) Payment::whereIn('status', ['completed', 'paid'])->sum('amount');
        $downpaymentRevenue = (float) Payment::whereIn('status', ['completed', 'paid'])
            ->where('payment_type', 'downpayment')
            ->sum('amount');
        $balanceRevenue = (float) Payment::whereIn('status', ['completed', 'paid'])
            ->whereIn('payment_type', ['balance_settlement', 'full'])
            ->sum('amount');
        $outstandingBalances = (float) Booking::where('status', 'confirmed')->sum('balance_amount');
        $refundsProcessed = (float) Payment::where('status', 'refunded')->sum('amount_refunded') 
            ?: (float) Payment::sum('amount_refunded') 
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
        $totalBookingsCount = max(1, Booking::where('status', '!=', 'pending_downpayment')->count());

        foreach ($packages as $key => $pkg) {
            $pBookings = Booking::where('status', '!=', 'pending_downpayment')->where('class_type', $key);
            $count = $pBookings->count();
            $paxCount = BookingParticipant::whereHas('booking', fn($q) => $q->where('status', '!=', 'pending_downpayment')->where('class_type', $key))->count();
            $rev = (float) Payment::whereIn('status', ['completed', 'paid'])
                ->whereHas('booking', fn($q) => $q->where('class_type', $key))
                ->sum('amount');

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
        $totalAdjustments = BookingPriceAdjustment::count();
        $positiveYield = (float) BookingPriceAdjustment::where('adjustment_amount', '>', 0)->sum('adjustment_amount');
        $discountGiven = (float) abs(BookingPriceAdjustment::where('adjustment_amount', '<', 0)->sum('adjustment_amount'));
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
        $recentAuditLogs = AuditLog::latest('created_at')->take(6)->get();

        // AI Demand & Revenue Forecast
        $forecastData = app(\App\Services\DemandForecastService::class)->getForecastData();

        return view('admin.dashboard', compact(
            'user',
            'isOwner',
            'activeView',
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
