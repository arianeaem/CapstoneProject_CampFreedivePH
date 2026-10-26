<?php

namespace App\Services\Checkout;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\CheckoutIntent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the official booking for a checkout intent from its encrypted payload: booking (new booking number and PIN,
 * generated only here), participants and price adjustments, exactly as quoted (never repriced). Shared by checkout
 * finalization and the Batch 3-II B2.4 allocation of a verified alternative date, which only changes the dates.
 * The caller holds the capacity locks and the intent row lock; bookings.checkout_intent_id is unique, so an intent
 * never gets a second booking.
 */
class IntentBookingBuilder
{
    public const PARTICIPANT_FIELDS = ['name', 'birthdate', 'gender', 'age', 'health_condition', 'swimmer_status', 'price_per_person'];
    public const GUARDIAN_FIELDS = ['contact_birthdate', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_consent_at'];
    private const BOOKING_NUMBER_ATTEMPTS = 5;

    /** @param ?string $startDate,$endDate another date than the quoted one (B2.4); null keeps the payload's dates */
    public function build(CheckoutIntent $intent, int $paidCentavos, Batch $batch, ?string $startDate = null, ?string $endDate = null): Booking
    {
        $payload = $intent->payload;
        $booking = $this->createOfficialBooking($intent, $paidCentavos, $batch, $startDate, $endDate);

        foreach ($payload['participants'] ?? [] as $participant) {
            $booking->participants()->create(array_intersect_key($participant, array_flip(self::PARTICIPANT_FIELDS)));
        }

        foreach ($payload['pricing']['adjustments'] ?? [] as $adjustment) {
            $booking->priceAdjustments()->create([
                'pricing_rule_id' => $adjustment['rule_id'],
                'rule_name' => $adjustment['rule_name'],
                'rule_type' => $adjustment['rule_type'],
                'condition_summary' => $adjustment['condition_summary'],
                'base_price' => $adjustment['base_price'],
                'adjustment_amount' => $adjustment['delta_per_pax'],
                'adjusted_price' => $adjustment['adjusted_price'],
            ]);
        }

        return $booking;
    }

    /** The booking row, with a new booking number and PIN. */
    private function createOfficialBooking(CheckoutIntent $intent, int $paidCentavos, Batch $batch, ?string $startDate, ?string $endDate): Booking
    {
        $payload = $intent->payload;
        $details = $payload['booking'];
        $contact = $payload['contact'];
        $fees = $payload['pricing']['fees'] ?? [];
        $paidPesos = round($paidCentavos / 100, 2);

        $attributes = [
            'batch_id' => $batch->id,
            'class_type' => $details['class_type'],
            'is_certified_diver' => (bool) ($details['is_certified_diver'] ?? false),
            'start_date' => $startDate ?? $details['start_date'],
            'end_date' => $endDate ?? $details['end_date'],
            'pickup_option' => $details['pickup_option'],
            'pickup_location' => $details['pickup_location'] ?? null,
            'carpool_fee' => $fees['carpool'] ?? 0,
            'boat_dive' => (bool) ($details['boat_dive'] ?? false),
            'boat_dive_fee' => $fees['boat_dive'] ?? 0,
            'lgu_fee' => $fees['lgu'] ?? 0,
            'environmental_fee' => $fees['environmental'] ?? 0,
            'subtotal' => $payload['pricing']['subtotal'],
            'total_amount' => (float) $intent->total_amount,
            'downpayment_amount' => (float) $intent->downpayment_amount,
            'balance_amount' => max(0, round((float) $intent->total_amount - $paidPesos, 2)),
            'contact_name' => $contact['name'],
            'contact_email' => $contact['email'],
            'contact_phone' => $contact['phone'],
            'contact_facebook' => $contact['facebook'] ?? null,
            'status' => 'confirmed',
        ] + array_intersect_key($payload['guardian'] ?? [], array_flip(self::GUARDIAN_FIELDS));

        // A booking-number collision is retried inside a savepoint, so the outer transaction stays usable (PostgreSQL)
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $intent) {
                    $booking = new Booking($attributes + [
                        'booking_number' => 'CFP-' . now()->format('Y') . '-' . strtoupper(Str::random(5)),
                        'pin' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                    ]);
                    $booking->forceFill(['checkout_intent_id' => $intent->id]);
                    $booking->save();

                    return $booking;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::BOOKING_NUMBER_ATTEMPTS || ! str_contains($e->getMessage(), 'booking_number')) {
                    throw $e;
                }
            }
        }
    }
}
