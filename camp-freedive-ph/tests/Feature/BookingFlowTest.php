<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\WeatherSafetyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the next upcoming Saturday's date string.
     * Used to satisfy the Saturday-only backend validation rule.
     */
    private function nextSaturday(int $weeksAhead = 1): string
    {
        $date = Carbon::now()->next(Carbon::SATURDAY);
        if ($weeksAhead > 1) {
            $date->addWeeks($weeksAhead - 1);
        }
        return $date->toDateString();
    }

    private function nextSunday(int $weeksAhead = 1): string
    {
        return Carbon::parse($this->nextSaturday($weeksAhead))->addDay()->toDateString();
    }

    /**
     * Build a minimal valid booking payload for use across tests.
     */
    private function validPayload(array $overrides = []): array
    {
        $start = $this->nextSaturday(2);
        $end   = Carbon::parse($start)->addDay()->toDateString();

        return array_merge([
            'class_type'          => 'discovery',
            'start_date'          => $start,
            'end_date'            => $end,
            'participants'        => [
                [
                    'first_name'       => 'Ariane',
                    'last_name'        => 'Santos',
                    'age'              => 25,
                    'health_condition' => 'None',
                    'swimmer_status'   => 'non_swimmer',
                ],
            ],
            'contact_first_name'  => 'Ariane',
            'contact_last_name'   => 'Santos',
            'contact_email'       => 'ariane@example.com',
            'contact_phone'       => '09171234567',
            'pickup_option'       => 'own',
            'boat_dive'           => false,
            'has_agreed_to_terms' => true,
            'confirmation_ack'    => true,
            'payment_method'      => 'paymongo',
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Basic page loading
    // -------------------------------------------------------------------------

    public function test_landing_page_loads_successfully(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Camp FreedivePH');
        $response->assertSee('Discovery');
        $response->assertSee('Fundive');
        $response->assertSee('Refinement');
        $response->assertSee('Manage Booking');
    }

    public function test_booking_page_loads(): void
    {
        $response = $this->get('/book?class=discovery');
        $response->assertStatus(200);
        $response->assertSee('Select Class');
        $response->assertSee('Discovery');
        $response->assertSee('2:30 AM');
    }

    public function test_weather_check_endpoint(): void
    {
        $start = $this->nextSaturday(3);
        $end   = Carbon::parse($start)->addDay()->toDateString();

        $response = $this->postJson('/api/weather/check', [
            'start_date' => $start,
            'end_date'   => $end,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'risk_level', 'title', 'badge_color', 'description', 'is_bookable',
            'day1' => ['date', 'classification', 'recommended_action'],
            'day2' => ['date', 'classification', 'recommended_action'],
        ]);
    }

    public function test_booking_is_blocked_when_selected_dates_are_critical_risk(): void
    {
        $this->mock(WeatherSafetyService::class, function ($mock): void {
            $mock->shouldReceive('getForecast')->once()->andReturn([
                'risk_level' => 'critical_risk',
                'overall_classification' => 'Critical Risk',
                'is_bookable' => false,
                'day1' => ['classification' => 'Critical Risk'],
                'day2' => ['classification' => 'High Risk'],
            ]);
        });

        $response = $this->postJson('/book', $this->validPayload());

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('weather.is_bookable', false);
        $response->assertJsonFragment([
            'message' => 'This date cannot be booked because the forecast is classified as Critical Risk. Please choose another date.',
        ]);
        $this->assertDatabaseCount('bookings', 0);
    }

    // -------------------------------------------------------------------------
    // Successful booking creation
    // -------------------------------------------------------------------------

    public function test_complete_booking_submission_creates_booking_and_payment(): void
    {
        $start = $this->nextSaturday(4);
        $end   = Carbon::parse($start)->addDay()->toDateString();

        $payload = [
            'class_type'          => 'discovery',
            'start_date'          => $start,
            'end_date'            => $end,
            'participants'        => [
                ['first_name' => 'Ariane', 'last_name' => 'Mae', 'age' => 25, 'health_condition' => 'None', 'swimmer_status' => 'non_swimmer'],
                ['first_name' => 'Bryan',  'last_name' => 'Santos', 'age' => 26, 'health_condition' => 'Mild dust allergy', 'swimmer_status' => 'swimmer'],
            ],
            'contact_first_name'  => 'Ariane',
            'contact_last_name'   => 'Mae',
            'contact_email'       => 'ariane@example.com',
            'contact_phone'       => '09171234567',
            'contact_facebook'    => 'https://www.facebook.com/arianemae',
            'pickup_option'       => 'own',
            'boat_dive'           => true,
            'has_agreed_to_terms' => true,
            'confirmation_ack'    => true,
            'payment_method'      => 'paymongo',
        ];

        $response = $this->postJson('/book', $payload);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success', 'booking_number', 'pin', 'booking_id',
            'downpayment_paid', 'balance_due', 'manage_url',
        ]);

        $this->assertDatabaseHas('bookings', [
            'contact_email'      => 'ariane@example.com',
            'class_type'         => 'discovery',
            'pickup_option'      => 'own',
            'downpayment_amount' => 4000.00,
        ]);
        $this->assertNotNull(Booking::where('contact_email', 'ariane@example.com')->first()->batch_id);
        $this->assertDatabaseCount('booking_participants', 2);
        $this->assertDatabaseCount('payments', 1);
    }

    // -------------------------------------------------------------------------
    // Manage Booking - lookup and reschedule
    // -------------------------------------------------------------------------

    public function test_manage_booking_lookup_and_policy_evaluation(): void
    {
        $start = $this->nextSaturday(3);
        $end   = Carbon::parse($start)->addDay()->toDateString();

        $booking = Booking::create([
            'booking_number'     => 'CFP-2026-9999',
            'pin'                => '1234',
            'class_type'         => 'discovery',
            'start_date'         => $start,
            'end_date'           => $end,
            'pickup_option'      => 'carpool',
            'pickup_location'    => 'Shell Tiendesitas (Pasig) - 3:00 AM',
            'carpool_fee'        => 2000.00,
            'boat_dive'          => false,
            'boat_dive_fee'      => 0.00,
            'lgu_fee'            => 300.00,
            'environmental_fee'  => 50.00,
            'subtotal'           => 4250.00,
            'total_amount'       => 6600.00,
            'downpayment_amount' => 3000.00,
            'balance_amount'     => 3600.00,
            'contact_name'       => 'Juan Test',
            'contact_email'      => 'juan@example.com',
            'contact_phone'      => '09170000000',
            'status'             => 'confirmed',
        ]);

        // Wrong PIN (must be 4 digits)
        $searchFail = $this->post('/manage-booking/search', [
            'booking_number' => 'CFP-2026-9999',
            'pin'            => '9999',
        ]);
        $searchFail->assertSessionHas('error', 'Booking not found - please check your details.');

        // Correct PIN
        $searchSuccess = $this->post('/manage-booking/search', [
            'booking_number' => 'CFP-2026-9999',
            'pin'            => '1234',
        ]);
        $searchSuccess->assertRedirect(route('manage.show', ['booking_number' => 'CFP-2026-9999', 'pin' => '1234']));

        // Show detail page
        $showResponse = $this->get('/manage-booking/CFP-2026-9999?pin=1234');
        $showResponse->assertStatus(200);
        $showResponse->assertSee('CFP-2026-9999');
        $showResponse->assertSee('Juan Test');
        $showResponse->assertSee('Allowed');

        // Reschedule to a future Saturday
        $newStart = $this->nextSaturday(5);
        $newEnd   = Carbon::parse($newStart)->addDay()->toDateString();

        $reschedResponse = $this->post('/manage-booking/CFP-2026-9999/reschedule', [
            'pin'                  => '1234',
            'requested_start_date' => $newStart,
            'requested_end_date'   => $newEnd,
            'reason'               => 'Schedule adjustment',
        ]);
        $reschedResponse->assertRedirect();
        $this->assertDatabaseHas('reschedule_requests', ['booking_id' => $booking->id, 'status' => 'pending']);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'reschedule_requested']);
    }

    // -------------------------------------------------------------------------
    // Capacity enforcement
    // -------------------------------------------------------------------------

    public function test_booking_fails_when_exceeding_batch_capacity_of_45_pax(): void
    {
        $start = $this->nextSaturday(5);
        $end   = Carbon::parse($start)->addDay()->toDateString();

        $existingBooking = Booking::create([
            'booking_number'     => 'CFP-2026-8888',
            'pin'                => '1234',
            'class_type'         => 'discovery',
            'start_date'         => $start,
            'end_date'           => $end,
            'pickup_option'      => 'own',
            'carpool_fee'        => 0,
            'boat_dive'          => false,
            'boat_dive_fee'      => 0,
            'lgu_fee'            => 300 * 44,
            'environmental_fee'  => 50 * 44,
            'subtotal'           => 4250 * 44,
            'total_amount'       => 4600 * 44,
            'downpayment_amount' => 2000 * 44,
            'balance_amount'     => 2600 * 44,
            'contact_name'       => 'Existing Group Leader',
            'contact_email'      => 'group@example.com',
            'contact_phone'      => '09170000000',
            'status'             => 'confirmed',
        ]);

        for ($i = 1; $i <= 44; $i++) {
            $existingBooking->participants()->create([
                'name'             => "Participant {$i}",
                'age'              => 25,
                'price_per_person' => 4250.00,
            ]);
        }

        $response = $this->postJson('/book', [
            'class_type'          => 'discovery',
            'start_date'          => $start,
            'end_date'            => $end,
            'participants'        => [
                ['first_name' => 'User', 'last_name' => 'Alpha', 'age' => 25, 'health_condition' => 'None', 'swimmer_status' => 'swimmer'],
                ['first_name' => 'User', 'last_name' => 'Beta',  'age' => 26, 'health_condition' => 'None', 'swimmer_status' => 'swimmer'],
            ],
            'contact_first_name'  => 'Overflow',
            'contact_last_name'   => 'Guest',
            'contact_email'       => 'overflow@example.com',
            'contact_phone'       => '09171234567',
            'pickup_option'       => 'own',
            'boat_dive'           => false,
            'has_agreed_to_terms' => true,
            'confirmation_ack'    => true,
            'payment_method'      => 'paymongo',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['success' => false]);
    }

    // -------------------------------------------------------------------------
    // Field validation
    // -------------------------------------------------------------------------

    public function test_booking_validation_rejects_invalid_phone_number(): void
    {
        $response = $this->postJson('/book', array_merge($this->validPayload(), [
            'contact_phone' => 'randomletters123',
        ]));
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_phone']);
    }

    public function test_booking_validation_rejects_invalid_email_format(): void
    {
        $response = $this->postJson('/book', array_merge($this->validPayload(), [
            'contact_email' => 'notanemailaddress',
        ]));
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_email']);
    }

    public function test_booking_validation_rejects_out_of_range_age(): void
    {
        $payload                           = $this->validPayload();
        $payload['participants'][0]['age'] = 999;
        $response                          = $this->postJson('/book', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['participants.0.age']);
    }

    // -------------------------------------------------------------------------
    // Filipino/Spanish names & suffix
    // -------------------------------------------------------------------------

    public function test_booking_creation_supports_filipino_spanish_names_and_suffix(): void
    {
        $start = $this->nextSaturday(3);
        $end   = Carbon::parse($start)->addDay()->toDateString();

        $response = $this->postJson('/book', [
            'class_type'          => 'discovery',
            'start_date'          => $start,
            'end_date'            => $end,
            'contact_first_name'  => 'Maria Ma.',
            'contact_middle_name' => 'Nuñez',
            'contact_last_name'   => 'Santos-Concepcion',
            'contact_suffix'      => 'Jr.',
            'contact_email'       => 'maria.concepcion@example.ph',
            'contact_phone'       => '09171234567',
            'pickup_option'       => 'own',
            'boat_dive'           => false,
            'has_agreed_to_terms' => true,
            'confirmation_ack'    => true,
            'payment_method'      => 'paymongo',
            'participants'        => [
                [
                    'first_name'       => 'Mary-Ann',
                    'middle_name'      => 'Santo Niño',
                    'last_name'        => 'De la Cruz',
                    'suffix'           => 'III',
                    'age'              => 24,
                    'health_condition' => 'None',
                    'swimmer_status'   => 'swimmer',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);
        $this->assertDatabaseHas('bookings', [
            'contact_name'  => 'Maria Ma. Nuñez Santos-Concepcion Jr.',
            'contact_email' => 'maria.concepcion@example.ph',
        ]);
        $this->assertDatabaseHas('booking_participants', [
            'name' => 'Mary-Ann Santo Niño De la Cruz III',
            'age'  => 24,
        ]);
    }

    public function test_booking_creation_with_no_middle_name_toggle(): void
    {
        $start = $this->nextSaturday(3);
        $end   = Carbon::parse($start)->addDay()->toDateString();

        $response = $this->postJson('/book', [
            'class_type'             => 'discovery',
            'start_date'             => $start,
            'end_date'               => $end,
            'contact_first_name'     => 'John Christopher Michael',
            'contact_middle_name'    => 'IgnoredMiddleName',
            'contact_no_middle_name' => 1,
            'contact_last_name'      => 'Reyes',
            'contact_suffix'         => '',
            'contact_email'          => 'john.reyes@example.ph',
            'contact_phone'          => '09171234568',
            'pickup_option'          => 'own',
            'boat_dive'              => false,
            'has_agreed_to_terms'    => true,
            'confirmation_ack'       => true,
            'payment_method'         => 'paymongo',
            'participants'           => [
                [
                    'first_name'       => 'Alex',
                    'no_middle_name'   => 1,
                    'last_name'        => 'Santos',
                    'suffix'           => 'II',
                    'age'              => 28,
                    'health_condition' => 'None',
                    'swimmer_status'   => 'swimmer',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);
        $this->assertDatabaseHas('bookings', [
            'contact_name'  => 'John Christopher Michael Reyes',
            'contact_email' => 'john.reyes@example.ph',
        ]);
        $this->assertDatabaseHas('booking_participants', [
            'name' => 'Alex Santos II',
            'age'  => 28,
        ]);
    }
}
