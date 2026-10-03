<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Payment;
use App\Services\SlotReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BookingSlotLockingTest extends TestCase
{
    use RefreshDatabase;

    protected SlotReservationService $slotService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slotService = app(SlotReservationService::class);
        Cache::flush();
    }

    public function test_slot_reservation_service_calculates_capacity_and_holds_correctly(): void
    {
        $startDate = '2026-10-10';
        $endDate = '2026-10-11';

        // 1. Initial State: 45 slots available
        $this->assertEquals(0, $this->slotService->getConfirmedPaxCount($startDate));
        $this->assertEquals(0, $this->slotService->getActiveHoldPaxCount($startDate));
        $this->assertEquals(45, $this->slotService->getAvailableSlots($startDate));

        // 2. Acquire a temporary hold for 3 pax
        $holdSuccess = $this->slotService->acquireHold($startDate, 'HOLD-001', 3, 900);
        $this->assertTrue($holdSuccess);
        $this->assertEquals(3, $this->slotService->getActiveHoldPaxCount($startDate));
        $this->assertEquals(42, $this->slotService->getAvailableSlots($startDate));

        // 3. Acquire another temporary hold for 2 pax
        $holdSuccess2 = $this->slotService->acquireHold($startDate, 'HOLD-002', 2, 900);
        $this->assertTrue($holdSuccess2);
        $this->assertEquals(5, $this->slotService->getActiveHoldPaxCount($startDate));
        $this->assertEquals(40, $this->slotService->getAvailableSlots($startDate));

        // 4. Release first hold
        $this->slotService->releaseHold($startDate, 'HOLD-001');
        $this->assertEquals(2, $this->slotService->getActiveHoldPaxCount($startDate));
        $this->assertEquals(43, $this->slotService->getAvailableSlots($startDate));
    }

    public function test_booking_submission_acquires_slot_hold_and_decrements_available_capacity(): void
    {
        $startDate = '2026-10-17';
        $endDate = '2026-10-18';

        $payload = [
            'class_type' => 'discovery',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'participants' => [
                ['first_name' => 'Diver', 'last_name' => 'One', 'name' => 'Diver One', 'age' => 24, 'health_condition' => 'None', 'swimmer_status' => 'swimmer'],
                ['first_name' => 'Diver', 'last_name' => 'Two', 'name' => 'Diver Two', 'age' => 26, 'health_condition' => 'None', 'swimmer_status' => 'swimmer'],
            ],
            'contact_first_name' => 'Diver',
            'contact_last_name' => 'One',
            'contact_name' => 'Diver One',
            'contact_email' => 'diver1@example.com',
            'contact_phone' => '09171234567',
            'pickup_option' => 'carpool',
            'pickup_location' => 'Shell Tiendesitas - 3:00 AM',
            'boat_dive' => false,
            'has_agreed_to_terms' => true,
            'confirmation_ack' => true,
            'payment_method' => 'paymongo',
        ];

        $response = $this->postJson('/book', $payload);

        $response->assertStatus(200);
        $data = $response->json();
        $bookingNumber = $data['booking_number'];

        // Temporary 15-min hold should be active in cache
        $this->assertEquals(2, $this->slotService->getActiveHoldPaxCount($startDate));
        $this->assertEquals(43, $this->slotService->getAvailableSlots($startDate));
    }

    public function test_booking_submission_blocks_when_capacity_exceeded_including_holds(): void
    {
        $startDate = '2026-10-24';
        $endDate = '2026-10-25';

        // Pre-fill 44 slots via temporary holds
        $this->slotService->acquireHold($startDate, 'EXISTING-HOLD', 44, 900);
        $this->assertEquals(1, $this->slotService->getAvailableSlots($startDate));

        $payload = [
            'class_type' => 'discovery',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'participants' => [
                ['first_name' => 'Guest', 'last_name' => 'Alpha', 'name' => 'Guest A', 'age' => 25, 'health_condition' => 'None', 'swimmer_status' => 'swimmer'],
                ['first_name' => 'Guest', 'last_name' => 'Beta', 'name' => 'Guest B', 'age' => 28, 'health_condition' => 'None', 'swimmer_status' => 'swimmer'],
            ],
            'contact_first_name' => 'Guest',
            'contact_last_name' => 'Alpha',
            'contact_name' => 'Guest A',
            'contact_email' => 'guest@example.com',
            'contact_phone' => '09171234567',
            'pickup_option' => 'carpool',
            'pickup_location' => 'Shell Tiendesitas - 3:00 AM',
            'boat_dive' => false,
            'has_agreed_to_terms' => true,
            'confirmation_ack' => true,
            'payment_method' => 'paymongo',
        ];

        $response = $this->postJson('/book', $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('Only 1 slot(s) remaining', $response->json('message'));
    }

    public function test_payment_confirmation_releases_temporary_hold_and_commits_pax(): void
    {
        $startDate = '2026-10-31';
        $endDate = '2026-11-01';

        $batch = Batch::create([
            'name' => 'Batch Test Lock',
            'batch_code' => 'BATCH-TEST-LOCK',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'max_capacity' => 45,
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_number' => 'CFP-2026-LOCK1',
            'pin' => '1234',
            'batch_id' => $batch->id,
            'class_type' => 'discovery',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'pickup_option' => 'own',
            'contact_name' => 'Confirmed Guest',
            'contact_email' => 'guest@example.com',
            'contact_phone' => '09171234567',
            'status' => 'pending_downpayment',
            'total_amount' => 4250,
            'downpayment_amount' => 3000,
            'balance_amount' => 1250,
        ]);

        BookingParticipant::create([
            'booking_id' => $booking->id,
            'name' => 'Confirmed Guest',
            'age' => 25,
            'price_per_person' => 4250,
        ]);

        // Register hold
        $this->slotService->acquireHold($startDate, $booking->booking_number, 1, 900);
        $this->assertEquals(1, $this->slotService->getActiveHoldPaxCount($startDate));

        // Simulate payment success callback
        $response = $this->get(route('paymongo.success', ['booking' => $booking->id]));
        $response->assertStatus(302);

        // Booking is now confirmed in DB
        $booking->refresh();
        $this->assertEquals('confirmed', $booking->status);

        // Temporary hold should be released
        $this->assertEquals(0, $this->slotService->getActiveHoldPaxCount($startDate));
        // But confirmed count in DB should be 1
        $this->assertEquals(1, $this->slotService->getConfirmedPaxCount($startDate));
        // Total available is 44
        $this->assertEquals(44, $this->slotService->getAvailableSlots($startDate));
    }
}
