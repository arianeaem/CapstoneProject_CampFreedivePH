<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\Carbon;

/**
 * Rules for participant reschedules and cancellations.
 *
 * Based on how many days are left before the dive, and if there is a storm warning
 * (PAGASA typhoon signal, Coast Guard gale warning):
 * 1. Storm / weather: free reschedule, or the downpayment back once staff confirm the weather cancellation
 *    (refund_requires_review: a forecast or rating alone never authorizes a refund).
 * 2. More than 14 days: free reschedule; a voluntary cancellation is not refunded (client policy, config/refunds.php).
 *    Bookings made under the earlier published terms (no refund_policy_version) keep the amount those terms quoted
 *    (100% of the downpayment), marked historical_terms for staff review: never repriced or paid automatically.
 * 3. 7 to 14 days: free reschedule only, no cancelling.
 * 4. Less than 7 days: no cancel or reschedule (coaches, boat and gear are already paid for).
 */
class BookingPolicyEngine
{
    /**
     * @param WeatherSafetyService $weatherService used to check storm warnings
     * @param SystemSettingService|null $settingService
     */
    public function __construct(
        protected WeatherSafetyService $weatherService,
        protected ?SystemSettingService $settingService = null
    ) {
        $this->settingService ??= app(SystemSettingService::class);
    }

    /**
     * Check what the participant can do with a booking right now.
     *
     * @param Booking $booking
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
     * }
     */
    public function evaluate(Booking $booking, \Carbon\Carbon|\DateTimeInterface|string|null $asOfDate = null): array
    {
        $now = $asOfDate ? Carbon::parse($asOfDate)->startOfDay() : Carbon::now()->startOfDay();
        $diveDate = Carbon::parse($booking->start_date)->startOfDay();
        $daysUntilDive = (int) $now->diffInDays($diveDate, false);

        $fullRefundDays = (int) ($this->settingService?->get('booking_cancellation.full_refund_threshold_days', 14) ?? 14);
        $rescheduleOnlyDays = (int) ($this->settingService?->get('booking_cancellation.reschedule_only_threshold_days', 7) ?? 7);

        // Is there a storm warning for the dive date?
        $isForceMajeure = $this->weatherService->isStormSignalActive($booking->start_date)
            // A Critical Risk rating on the batch counts too (same as the participant email)
            || ($booking->batch?->risk_classification === 'critical_risk' && $daysUntilDive >= 0);

        $historicalTerms = $booking->refund_policy_version === null;
        $refundRequiresReview = false;

        // Pick the policy based on days left and weather
        if ($isForceMajeure) {
            // Safety first: free move, or the downpayment back once staff confirm the trip cannot go ahead
            $policyTier = 'force_majeure';
            $refundPercentage = 100;
            $calculatedRefund = (float) $booking->downpayment_amount;
            $refundRequiresReview = true;
            $rescheduleAllowed = true;
            $cancelAllowed = true;
            $rescheduleMessage = 'Storm/Typhoon Warning Active: Free reschedule granted due to marine safety advisory.';
            $cancelMessage = 'Storm/Typhoon Warning Active: you may move to another date for free, or request a refund of your downpayment (₱'
                . number_format($booking->downpayment_amount, 2) . '). Our team confirms the weather situation before any refund.';
        } elseif ($daysUntilDive > $fullRefundDays && $historicalTerms) {
            // Booked under the earlier published terms (100% downpayment refund): kept as quoted, decided by staff
            $policyTier = 'more_than_two_weeks';
            $refundPercentage = 100;
            $calculatedRefund = (float) $booking->downpayment_amount;
            $refundRequiresReview = true;
            $rescheduleAllowed = true;
            $cancelAllowed = true;
            $rescheduleMessage = "Allowed: More than {$fullRefundDays} days before dive date. Free reschedule to any available safe batch.";
            $cancelMessage = 'Your booking was made under our earlier terms. You can move to another date for free; a cancellation request is reviewed by our team under the terms shown when you booked.';
        } elseif ($daysUntilDive > $fullRefundDays) {
            // Enough time: free reschedule; a voluntary cancellation is not refunded
            $policyTier = 'more_than_two_weeks';
            $refundPercentage = 0;
            $calculatedRefund = 0.00;
            $rescheduleAllowed = true;
            $cancelAllowed = true;
            $rescheduleMessage = "Allowed: More than {$fullRefundDays} days before dive date. Free reschedule to any available safe batch.";
            $cancelMessage = 'The downpayment is non-refundable if you cancel. You can move to another available date for free instead.';
        } elseif ($daysUntilDive >= $rescheduleOnlyDays && $daysUntilDive <= $fullRefundDays) {
            // Free reschedule only; cancelling is not allowed
            $policyTier = 'within_two_weeks';
            $refundPercentage = 0;
            $calculatedRefund = 0.00;
            $rescheduleAllowed = true;
            $cancelAllowed = false;
            $rescheduleMessage = "Allowed: Within {$rescheduleOnlyDays} to {$fullRefundDays} days window. Free reschedule to another available date.";
            $cancelMessage = "Not Allowed: Cancellations are not accepted within {$rescheduleOnlyDays} to {$fullRefundDays} days of the dive date. You can still reschedule for free.";
        } else {
            // Too close to the dive: coaches, rooms and boat are already booked
            $policyTier = 'within_one_week';
            $refundPercentage = 0;
            $calculatedRefund = 0.00;
            $rescheduleAllowed = false;
            $cancelAllowed = false;
            $rescheduleMessage = "Not Allowed: Rescheduling closes {$rescheduleOnlyDays} days before the dive date as coach, boat, and resort commitments are locked in.";
            $cancelMessage = "Not Allowed: Cancellations within {$rescheduleOnlyDays} days of the dive date are not accepted unless an official Typhoon/Coast Guard Gale warning creates a force-majeure situation.";
        }

        // Block new requests if one is already pending or the booking is cancelled
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
            'historical_terms' => $historicalTerms,
            'refund_requires_review' => $refundRequiresReview,
            'refund_policy_version' => $booking->refund_policy_version,
        ];
    }
}
