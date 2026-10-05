<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    /**
     * Helper to get a future date on a specific day of week (1 = Monday, ..., 7 = Sunday).
     */
    private function futureDayOfWeek(int $targetIsoDayOfWeek, int $weeksAhead = 2): Carbon
    {
        $date = Carbon::now()->addWeeks($weeksAhead)->startOfWeek()->addDays($targetIsoDayOfWeek - 1);
        if ($date->isPast()) {
            $date->addWeeks(2);
        }
        return $date;
    }

    /**
     * Helper to construct a standard valid base payload.
     */
    private function basePayload(array $overrides = []): array
    {
        $start = Carbon::now()->addDays(14)->toDateString();
        $end = Carbon::now()->addDays(15)->toDateString();

        return array_merge([
            'class_type' => 'discovery',
            'start_date' => $start,
            'end_date' => $end,
            'participants' => [
                [
                    'first_name' => 'Juan',
                    'last_name' => 'Dela Cruz',
                    'age' => 25,
                    'health_condition' => 'None',
                    'swimmer_status' => 'swimmer',
                ],
            ],
            'contact_first_name' => 'Juan',
            'contact_last_name' => 'Dela Cruz',
            'contact_email' => 'juan@example.ph',
            'contact_phone' => '09171234567',
            'pickup_option' => 'own',
            'boat_dive' => false,
            'has_agreed_to_terms' => true,
            'confirmation_ack' => true,
            'payment_method' => 'paymongo',
        ], $overrides);
    }

    // =========================================================================
    // 1. Name validation
    // =========================================================================

    public function test_booking_accepts_valid_names(): void
    {
        $validNames = [
            'Juan',
            'Mary Jane',
            'Anne-Marie',
            "O'Connor",
            'José',
            'Ma. Teresa',
            'De la Cruz',
            'Ñoño',
        ];

        foreach ($validNames as $name) {
            $payload = $this->basePayload([
                'contact_first_name' => $name,
                'contact_last_name' => 'Santos',
                'participants' => [
                    [
                        'first_name' => $name,
                        'last_name' => 'Santos',
                        'age' => 24,
                        'health_condition' => 'None',
                        'swimmer_status' => 'swimmer',
                    ],
                ],
            ]);

            $response = $this->postJson('/book', $payload);
            $response->assertStatus(200);
            $response->assertJsonFragment(['success' => true]);
        }
    }

    public function test_booking_rejects_empty_and_invalid_names(): void
    {
        $invalidNames = [
            '',                 // empty
            '   ',              // whitespace only
            '123456',           // numbers only
            '!@#$%',            // punctuation only
            '...',              // periods only
            'A',                // single char (fails min:2)
            str_repeat('A', 121), // excessively long (>120)
        ];

        foreach ($invalidNames as $invalid) {
            // Test participant first name
            $payload = $this->basePayload();
            $payload['participants'][0]['first_name'] = $invalid;
            $res = $this->postJson('/book', $payload);
            $res->assertStatus(422);
            $res->assertJsonValidationErrors(['participants.0.first_name']);

            // Test participant last name
            $payload = $this->basePayload();
            $payload['participants'][0]['last_name'] = $invalid;
            $res = $this->postJson('/book', $payload);
            $res->assertStatus(422);
            $res->assertJsonValidationErrors(['participants.0.last_name']);

            // Test contact first name
            $payload = $this->basePayload();
            $payload['contact_first_name'] = $invalid;
            $res = $this->postJson('/book', $payload);
            $res->assertStatus(422);
            $res->assertJsonValidationErrors(['contact_first_name']);

            // Test contact last name
            $payload = $this->basePayload();
            $payload['contact_last_name'] = $invalid;
            $res = $this->postJson('/book', $payload);
            $res->assertStatus(422);
            $res->assertJsonValidationErrors(['contact_last_name']);
        }
    }

    // =========================================================================
    // 2. Booking dates (all 7 two-day schedules)
    // =========================================================================

    public function test_all_seven_consecutive_day_transitions_are_valid(): void
    {
        // 1: Monday -> Tuesday
        // 2: Tuesday -> Wednesday
        // 3: Wednesday -> Thursday
        // 4: Thursday -> Friday
        // 5: Friday -> Saturday
        // 6: Saturday -> Sunday
        // 7: Sunday -> Monday
        for ($isoDay = 1; $isoDay <= 7; $isoDay++) {
            $startDate = $this->futureDayOfWeek($isoDay, 3 + $isoDay);
            $endDate = $startDate->copy()->addDay();

            $payload = $this->basePayload([
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ]);

            $response = $this->postJson('/book', $payload);
            $response->assertStatus(200);
            $response->assertJsonFragment(['success' => true]);
        }
    }

    public function test_booking_rejects_invalid_date_ranges(): void
    {
        // 1. Past start date
        $payload = $this->basePayload([
            'start_date' => Carbon::yesterday()->toDateString(),
            'end_date' => Carbon::today()->toDateString(),
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_date']);

        // 2. Same start and end date (same-day booking)
        $futureStart = Carbon::now()->addDays(14)->toDateString();
        $payload = $this->basePayload([
            'start_date' => $futureStart,
            'end_date' => $futureStart,
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);

        // 3. End date before start date
        $payload = $this->basePayload([
            'start_date' => Carbon::now()->addDays(16)->toDateString(),
            'end_date' => Carbon::now()->addDays(14)->toDateString(),
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);

        // 4. Start date -> 2 days later (e.g. 3 days / 2 nights)
        $payload = $this->basePayload([
            'start_date' => $futureStart,
            'end_date' => Carbon::parse($futureStart)->addDays(2)->toDateString(),
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);

        // 5. Start date -> one week later
        $payload = $this->basePayload([
            'start_date' => $futureStart,
            'end_date' => Carbon::parse($futureStart)->addDays(7)->toDateString(),
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);

        // 6. Start date -> two weeks later
        $payload = $this->basePayload([
            'start_date' => $futureStart,
            'end_date' => Carbon::parse($futureStart)->addDays(14)->toDateString(),
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);
    }

    // =========================================================================
    // 3. Terms and confirmation checkboxes
    // =========================================================================

    public function test_booking_rejects_missing_or_unaccepted_confirmation(): void
    {
        // Missing confirmation_ack
        $payload = $this->basePayload();
        unset($payload['confirmation_ack']);
        $res = $this->postJson('/book', $payload);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['confirmation_ack']);

        // confirmation_ack = false
        $payload = $this->basePayload(['confirmation_ack' => false]);
        $res = $this->postJson('/book', $payload);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['confirmation_ack']);

        // confirmation_ack = 0
        $payload = $this->basePayload(['confirmation_ack' => 0]);
        $res = $this->postJson('/book', $payload);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['confirmation_ack']);

        // Terms and Privacy confirmation is separate from accuracy confirmation.
        $payload = $this->basePayload();
        unset($payload['has_agreed_to_terms']);
        $res = $this->postJson('/book', $payload);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['has_agreed_to_terms']);

        $payload = $this->basePayload(['has_agreed_to_terms' => false]);
        $res = $this->postJson('/book', $payload);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['has_agreed_to_terms']);
    }

    // =========================================================================
    // 4. Allowed pickup locations
    // =========================================================================

    public function test_pickup_location_allowlist_enforcement(): void
    {
        // Configured location should succeed
        $payload = $this->basePayload([
            'pickup_option' => 'carpool',
            'pickup_location' => 'Shell Tiendesitas - 3:00 AM',
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(200);

        // Arbitrary unconfigured location should fail
        $payload = $this->basePayload([
            'pickup_option' => 'carpool',
            'pickup_location' => 'Secret Location XYZ',
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['pickup_location']);

        // Missing location when pickup_option = carpool should fail
        $payload = $this->basePayload([
            'pickup_option' => 'carpool',
            'pickup_location' => null,
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['pickup_location']);

        // When pickup_option = own, pickup_location can be null/omitted
        $payload = $this->basePayload([
            'pickup_option' => 'own',
            'pickup_location' => null,
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(200);
    }

    // =========================================================================
    // 5. Suffix
    // =========================================================================

    public function test_suffix_validation_allows_valid_and_rejects_arbitrary(): void
    {
        $validSuffixes = ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V', 'None'];
        foreach ($validSuffixes as $suffix) {
            $payload = $this->basePayload([
                'contact_suffix' => $suffix,
                'participants' => [
                    [
                        'first_name' => 'Juan',
                        'last_name' => 'Dela Cruz',
                        'suffix' => $suffix,
                        'age' => 25,
                        'health_condition' => 'None',
                        'swimmer_status' => 'swimmer',
                    ],
                ],
            ]);
            $response = $this->postJson('/book', $payload);
            $response->assertStatus(200);
        }

        // Arbitrary suffix string
        $payload = $this->basePayload([
            'contact_suffix' => 'Superstar',
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_suffix']);

        $payload = $this->basePayload();
        $payload['participants'][0]['suffix'] = 'ArbitrarySuffix';
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['participants.0.suffix']);
    }

    // =========================================================================
    // 6. Payment method
    // =========================================================================

    public function test_payment_method_validation(): void
    {
        // Supported payment method
        $payload = $this->basePayload(['payment_method' => 'paymongo']);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(200);

        // Unsupported / arbitrary payment method
        $payload = $this->basePayload(['payment_method' => 'unsupported_method']);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
    }

    // =========================================================================
    // 7. Price quote
    // =========================================================================

    public function test_pricing_quote_endpoint_validates_class_type(): void
    {
        $futureStart = Carbon::now()->addDays(20)->toDateString();

        foreach (['discovery', 'fundive', 'refinement'] as $classType) {
            $response = $this->postJson('/api/pricing/quote', [
                'class_type' => $classType,
                'start_date' => $futureStart,
                'is_certified_diver' => false,
                'participants_count' => 2,
            ]);
            $response->assertStatus(200);
            $response->assertJsonStructure(['base_price_per_pax', 'adjusted_price_per_pax', 'subtotal']);
        }

        // Invalid class type
        $response = $this->postJson('/api/pricing/quote', [
            'class_type' => 'invalid_class',
            'start_date' => $futureStart,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['class_type']);
    }

    // =========================================================================
    // 8. Fundive certification field
    // =========================================================================

    public function test_fundive_certification_field_validation(): void
    {
        // When class_type = fundive, is_certified_diver = true should be valid
        $payload = $this->basePayload([
            'class_type' => 'fundive',
            'is_certified_diver' => true,
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(200);

        // When class_type = fundive, is_certified_diver = false should be valid
        $payload = $this->basePayload([
            'class_type' => 'fundive',
            'is_certified_diver' => false,
        ]);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(200);

        // When class_type = fundive, is_certified_diver completely absent should be rejected
        $payload = $this->basePayload([
            'class_type' => 'fundive',
        ]);
        unset($payload['is_certified_diver']);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_certified_diver']);

        // When class_type = discovery, is_certified_diver can be absent
        $payload = $this->basePayload([
            'class_type' => 'discovery',
        ]);
        unset($payload['is_certified_diver']);
        $response = $this->postJson('/book', $payload);
        $response->assertStatus(200);
    }

    // =========================================================================
    // 9. Manage Booking input (booking number and PIN)
    // =========================================================================

    public function test_manage_booking_pin_and_number_validation(): void
    {
        // 1. Valid booking number format & 4-digit PINs (0000, 1234, 9999)
        $booking = Booking::create([
            'booking_number' => 'CFP-2026-AB123',
            'pin' => '0000',
            'class_type' => 'discovery',
            'start_date' => Carbon::now()->addDays(20)->toDateString(),
            'end_date' => Carbon::now()->addDays(21)->toDateString(),
            'pickup_option' => 'own',
            'total_amount' => 4250.00,
            'downpayment_amount' => 2000.00,
            'balance_amount' => 2250.00,
            'contact_name' => 'Juan Dela Cruz',
            'contact_email' => 'juan@example.ph',
            'contact_phone' => '09171234567',
            'status' => 'confirmed',
        ]);

        foreach (['0000', '1234', '9999'] as $validPin) {
            $booking->update(['pin' => $validPin]);
            $res = $this->post('/manage-booking/search', [
                'booking_number' => 'CFP-2026-AB123',
                'pin' => $validPin,
            ]);
            $res->assertRedirect(route('manage.show', ['booking_number' => 'CFP-2026-AB123', 'pin' => $validPin]));
        }

        // 2. Reject invalid PINs: 12, 12345, abcd, 12 3
        $invalidPins = ['12', '12345', 'abcd', '12 3'];
        foreach ($invalidPins as $invalidPin) {
            $res = $this->post('/manage-booking/search', [
                'booking_number' => 'CFP-2026-AB123',
                'pin' => $invalidPin,
            ]);
            $res->assertSessionHasErrors(['pin']);
        }

        // 3. Reject invalid booking number formats
        $invalidNumbers = ['INVALID-123', 'CFP-26-AB123', 'random-text'];
        foreach ($invalidNumbers as $invNum) {
            $res = $this->post('/manage-booking/search', [
                'booking_number' => $invNum,
                'pin' => '1234',
            ]);
            $res->assertSessionHasErrors(['booking_number']);
        }
    }

    // =========================================================================
    // 10. Reschedule (all 7 day changes and rejections)
    // =========================================================================

    public function test_reschedule_accepts_all_seven_consecutive_day_transitions(): void
    {
        // Test each of the 7 consecutive-day transitions
        for ($isoDay = 1; $isoDay <= 7; $isoDay++) {
            $booking = Booking::create([
                'booking_number' => sprintf('CFP-2026-RS0%d', $isoDay),
                'pin' => '1234',
                'class_type' => 'discovery',
                'start_date' => Carbon::now()->addDays(20)->toDateString(),
                'end_date' => Carbon::now()->addDays(21)->toDateString(),
                'pickup_option' => 'own',
                'total_amount' => 4250.00,
                'downpayment_amount' => 2000.00,
                'balance_amount' => 2250.00,
                'contact_name' => 'Juan Dela Cruz',
                'contact_email' => 'juan@example.ph',
                'contact_phone' => '09171234567',
                'status' => 'confirmed',
            ]);

            Payment::create([
                'booking_id' => $booking->id,
                'payment_type' => 'downpayment',
                'payment_method' => 'paymongo',
                'transaction_id' => 'TXN-' . $isoDay . '-' . uniqid(),
                'amount' => 2000.00,
                'status' => 'completed',
                'paid_at' => now(),
            ]);

            $newStart = $this->futureDayOfWeek($isoDay, 4 + $isoDay);
            $newEnd = $newStart->copy()->addDay();

            $res = $this->post("/manage-booking/{$booking->booking_number}/reschedule", [
                'pin' => '1234',
                'requested_start_date' => $newStart->toDateString(),
                'requested_end_date' => $newEnd->toDateString(),
                'reason' => 'Schedule update to ' . $newStart->format('l'),
            ]);

            $res->assertRedirect();
            $this->assertDatabaseHas('reschedule_requests', [
                'booking_id' => $booking->id,
                'status' => 'pending',
            ]);
            $latestRequest = \App\Models\RescheduleRequest::where('booking_id', $booking->id)->latest('id')->first();
            $this->assertNotNull($latestRequest);
            $this->assertEquals($newStart->toDateString(), $latestRequest->requested_start_date->toDateString());
            $this->assertEquals($newEnd->toDateString(), $latestRequest->requested_end_date->toDateString());
        }
    }

    public function test_reschedule_rejects_invalid_date_ranges(): void
    {
        $booking = Booking::create([
            'booking_number' => 'CFP-2026-RES02',
            'pin' => '1234',
            'class_type' => 'discovery',
            'start_date' => Carbon::now()->addDays(20)->toDateString(),
            'end_date' => Carbon::now()->addDays(21)->toDateString(),
            'pickup_option' => 'own',
            'total_amount' => 4250.00,
            'downpayment_amount' => 2000.00,
            'balance_amount' => 2250.00,
            'contact_name' => 'Juan Dela Cruz',
            'contact_email' => 'juan@example.ph',
            'contact_phone' => '09171234567',
            'status' => 'confirmed',
        ]);

        Payment::create([
            'booking_id' => $booking->id,
            'payment_type' => 'downpayment',
            'payment_method' => 'paymongo',
            'transaction_id' => 'TXN-' . uniqid(),
            'amount' => 2000.00,
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        // 1. Past start date
        $res = $this->post("/manage-booking/{$booking->booking_number}/reschedule", [
            'pin' => '1234',
            'requested_start_date' => Carbon::yesterday()->toDateString(),
            'requested_end_date' => Carbon::today()->toDateString(),
        ]);
        $res->assertSessionHasErrors(['requested_start_date']);

        // 2. Same-day range
        $target = Carbon::now()->addDays(25)->toDateString();
        $res = $this->post("/manage-booking/{$booking->booking_number}/reschedule", [
            'pin' => '1234',
            'requested_start_date' => $target,
            'requested_end_date' => $target,
        ]);
        $res->assertSessionHasErrors(['requested_end_date']);

        // 3. End date before start date
        $res = $this->post("/manage-booking/{$booking->booking_number}/reschedule", [
            'pin' => '1234',
            'requested_start_date' => Carbon::now()->addDays(25)->toDateString(),
            'requested_end_date' => Carbon::now()->addDays(23)->toDateString(),
        ]);
        $res->assertSessionHasErrors(['requested_end_date']);

        // 4. Range longer than one night (e.g. 2 days or 1 week)
        $res = $this->post("/manage-booking/{$booking->booking_number}/reschedule", [
            'pin' => '1234',
            'requested_start_date' => $target,
            'requested_end_date' => Carbon::parse($target)->addDays(2)->toDateString(),
        ]);
        $res->assertSessionHasErrors(['requested_end_date']);

        $res = $this->post("/manage-booking/{$booking->booking_number}/reschedule", [
            'pin' => '1234',
            'requested_start_date' => $target,
            'requested_end_date' => Carbon::parse($target)->addDays(7)->toDateString(),
        ]);
        $res->assertSessionHasErrors(['requested_end_date']);
    }
}
