<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\Carbon;

/**
 * Booking Policy & Penalty Calculation Engine.
 *
 * Operational & Business Context:
 * Evaluates dynamic guest reschedule and cancellation eligibility based on lead time
 * to the dive weekend and active maritime force majeure conditions (e.g., PAGASA Typhoon
 * Signals, PCG Gale Warnings).
 *
 * Financial & Marine Safety Rationale:
 * 1. Force Majeure: 100% full downpayment refund or free reschedule granted immediately
 *    to protect diver safety and comply with Philippine Coast Guard advisory orders.
 * 2. > 14 Days Out: 100% refund or free reschedule allowed as resort slots and outrigger
 *    bancas can still be re-allocated to waitlisted divers.
 * 3. 7 to 14 Days Out: Rescheduling is free, but cancellation is accepted with 0% refund
 *    under the capstone policy.
 * 4. < 7 Days Out: Cancellation and rescheduling are locked out because instructor payroll,
 *    boat fuel, and gear transport are non-refundable and committed.
 */
class BookingPolicyEngine
{
    /**
     * @param WeatherSafetyService $weatherService Maritime safety evaluation service for storm signal checks
     * @param SystemSettingService|null $settingService Centralized system settings service
     */
    public function __construct(
        protected WeatherSafetyService $weatherService,
        protected ?SystemSettingService $settingService = null
    ) {
        $this->settingService ??= app(SystemSettingService::class);
    }

    /**
     * Evaluates live reschedule eligibility, refund percentage, and customer-facing advisories for a booking.
     *
     * @param Booking $booking The active booking instance to evaluate
     * @return array{
     *     days_until_dive: int,
     *     is_force_majeure: bool,
     *     reschedule_allowed: bool,
     *     reschedule_message: string,
     *     cancel_allowed: bool,
     *     cancel_message: string,
     *     refund_percentage: int,
     *     calculated_refund: float,
     *     policy_tier: string,
     *     has_pending_reschedule: bool,
     *     has_pending_cancellation: bool,
     *     is_cancelled: bool
     * } Structured evaluation result
     */
    public function evaluate(Booking $booking, \Carbon\Carbon|\DateTimeInterface|string|null $asOfDate = null): array
    {
        $now = $asOfDate ? Carbon::parse($asOfDate)->startOfDay() : Carbon::now()->startOfDay();
        $diveDate = Carbon::parse($booking->start_date)->startOfDay();
        $daysUntilDive = (int) $now->diffInDays($diveDate, false);

        $fullRefundDays = (int) ($this->settingService?->get('booking_cancellation.full_refund_threshold_days', 14) ?? 14);
        $rescheduleOnlyDays = (int) ($this->settingService?->get('booking_cancellation.reschedule_only_threshold_days', 7) ?? 7);

        // Check if there is an active storm / typhoon warning for that dive date in Mabini, Batangas
        $isForceMajeure = $this->weatherService->isStormSignalActive($booking->start_date);

        // Base policy tier calculation based on days until dive & force majeure
        if ($isForceMajeure) {
            // Diver safety takes precedence: full downpayment refund on PCG gale / typhoon warnings
            $policyTier = 'force_majeure';
            $refundPercentage = 100;
            $calculatedRefund = (float) $booking->downpayment_amount;
            $rescheduleAllowed = true;
            $cancelAllowed = true;
            $rescheduleMessage = 'Storm/Typhoon Warning Active: Free reschedule granted due to marine safety advisory.';
            $cancelMessage = 'Storm/Typhoon Warning Active: 100% full refund available due to force majeure.';
        } elseif ($daysUntilDive > $fullRefundDays) {
            // Ample notice window: full refund or free reschedule as logistics can be rebooked
            $policyTier = 'more_than_two_weeks';
            $refundPercentage = 100;
            $calculatedRefund = (float) $booking->downpayment_amount;
            $rescheduleAllowed = true;
            $cancelAllowed = false;
            $rescheduleMessage = "Allowed: More than {$fullRefundDays} days before dive date. Free reschedule to any available safe batch.";
            $cancelMessage = 'Eligible for 100% Full Downpayment Refund (₱' . number_format($booking->downpayment_amount, 2) . ') or Free Reschedule.';
        } elseif ($daysUntilDive >= $rescheduleOnlyDays && $daysUntilDive <= $fullRefundDays) {
            // Mid-range window: free reschedule allowed to retain customer; cancellation forfeits downpayment
            $policyTier = 'within_two_weeks';
            $refundPercentage = 0;
            $calculatedRefund = 0.00;
            $rescheduleAllowed = true;
            $cancelAllowed = true;
            $rescheduleMessage = "Allowed: Within {$rescheduleOnlyDays} to {$fullRefundDays} days window. Free reschedule to another available date.";
            $cancelMessage = "0% Refund (Downpayment Forfeited): Cancellations made within {$rescheduleOnlyDays} to {$fullRefundDays} days forfeit downpayment (free reschedule is permitted).";
        } else {
            // < rescheduleOnlyDays: strict lockout because coach staffing, resort rooms, and boat charter deposits are locked
            $policyTier = 'within_one_week';
            $refundPercentage = 0;
            $calculatedRefund = 0.00;
            $rescheduleAllowed = false;
            $cancelAllowed = false;
            $rescheduleMessage = "Not Allowed: Rescheduling closes {$rescheduleOnlyDays} days before the dive date as coach, boat, and resort commitments are locked in.";
            $cancelMessage = "Not Allowed: Cancellations within {$rescheduleOnlyDays} days of the dive date are not accepted unless an official Typhoon/Coast Guard Gale warning creates a force-majeure situation.";
        }

        // Status-specific overrides for self-service submission limits to prevent duplicate processing
        $hasPendingReschedule = ($booking->status === 'reschedule_requested');
        $hasPendingCancellation = ($booking->status === 'cancellation_requested');
        $isCancelled = in_array($booking->status, ['cancelled', 'cancelled_by_camp', 'cancelled_by_guest']);

        return [
            'days_until_dive' => $daysUntilDive,
            'is_force_majeure' => $isForceMajeure,
            'reschedule_allowed' => $isCancelled ? false : ($hasPendingReschedule ? false : $rescheduleAllowed),
            'reschedule_message' => $hasPendingReschedule 
                ? 'You already have a pending reschedule request under review by the camp.' 
                : ($isCancelled ? 'This booking is already cancelled.' : $rescheduleMessage),
            'cancel_allowed' => $isCancelled ? false : ($hasPendingCancellation ? false : $cancelAllowed),
            'cancel_message' => $hasPendingCancellation 
                ? 'A cancellation request is currently under review by the camp.' 
                : ($isCancelled ? 'This booking is already cancelled.' : $cancelMessage),
            'refund_percentage' => $refundPercentage,
            'calculated_refund' => $calculatedRefund,
            'policy_tier' => $policyTier,
            'has_pending_reschedule' => $hasPendingReschedule,
            'has_pending_cancellation' => $hasPendingCancellation,
            'is_cancelled' => $isCancelled,
        ];
    }
}
