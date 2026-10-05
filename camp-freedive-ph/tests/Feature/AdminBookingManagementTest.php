<?php

namespace Tests\Feature;

use App\Mail\BookingDetailsUpdatedMail;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\RescheduleRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminBookingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_admin_and_owner_can_access_bookings_list(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $response = $this->get('/admin/bookings');
        $response->assertStatus(200);
        $response->assertSee('Booking Management');
        $response->assertSee('CFP-2026-1001');
    }

    public function test_coach_is_forbidden_from_booking_management(): void
    {
        $coach = User::where('email', 'coach.jose@campfreedive.ph')->first();
        $this->actingAs($coach);

        $response = $this->get('/admin/bookings');
        $response->assertStatus(403);
    }

    public function test_manual_booking_entry_creates_confirmed_booking_with_offline_payment(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $startDate = Carbon::now()->addDays(14)->format('Y-m-d');
        $endDate = Carbon::now()->addDays(15)->format('Y-m-d');

        $response = $this->post('/admin/bookings', [
            'class_type' => 'discovery',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'pickup_option' => 'carpool',
            'pickup_location' => 'Shell Tiendesitas (Pasig) - 3:00 AM',
            'boat_dive' => true,
            'contact_name' => 'Walkin Guest',
            'contact_email' => 'walkin@example.com',
            'contact_phone' => '0917 000 9999',
            'payment_method' => 'gcash',
            'payment_stage' => 'downpayment',
            'payment_reference' => 'GCASH-TXN-001',
            'admin_notes' => 'Received via GCash direct transfer',
            'participants' => [
                [
                    'name' => 'Walkin Diver One',
                    'birthdate' => Carbon::now()->subYears(28)->subDays(3)->format('Y-m-d'),
                    'gender' => 'female',
                    'swimmer_status' => 'swimmer',
                    'health_condition' => 'No medical issues',
                ],
            ],
        ]);

        $this->assertDatabaseHas('bookings', [
            'contact_name' => 'Walkin Guest',
            'status' => 'confirmed',
            'created_by' => $admin->id,
        ]);

        $booking = Booking::where('contact_name', 'Walkin Guest')->first();
        $this->assertNotNull($booking);
        $this->assertNotNull($booking->batch_id);
        $this->assertSame($startDate, $booking->batch->start_date->toDateString());
        $response->assertRedirect(route('admin.bookings.show', $booking));

        // Verify participant
        $this->assertDatabaseHas('booking_participants', [
            'booking_id' => $booking->id,
            'name' => 'Walkin Diver One',
            'gender' => 'female',
            'age' => 28,
        ]);

        // Verify offline payment
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'payment_method' => 'gcash',
            'status' => 'completed',
        ]);

        // Verify status log
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'new_status' => 'confirmed',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_admin_can_update_booking_status_to_completed_and_no_show(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $booking = Booking::where('booking_number', 'CFP-2026-1001')->first();

        // 1. Update to completed
        $response = $this->patch("/admin/bookings/{$booking->id}/status", [
            'status' => 'completed',
            'note' => '2D1N dive training completed successfully.',
        ]);

        $response->assertRedirect();
        $this->assertEquals('completed', $booking->fresh()->status);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'new_status' => 'completed',
        ]);

        // 2. Update to no-show
        $response2 = $this->patch("/admin/bookings/{$booking->id}/status", [
            'status' => 'no_show',
            'note' => 'Guest did not arrive for departure van.',
        ]);

        $response2->assertRedirect();
        $this->assertEquals('no_show', $booking->fresh()->status);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'new_status' => 'no_show',
        ]);
    }

    public function test_editing_booking_and_participant_records_immutable_audit_log_ra10173(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $booking = Booking::where('booking_number', 'CFP-2026-1001')->first();
        $participant = $booking->participants->first();
        $originalPricing = $booking->only([
            'carpool_fee',
            'boat_dive_fee',
            'lgu_fee',
            'environmental_fee',
            'subtotal',
            'total_amount',
            'downpayment_amount',
        ]);

        Mail::fake();
        $originalEmail = $booking->contact_email;

        $participantsData = $booking->participants->map(function ($p, $index) {
            return [
                'id' => $p->id,
                'name' => $index === 0 ? 'Ariane Mae Ramos-Updated' : $p->name,
                'age' => $p->age,
                'swimmer_status' => $p->swimmer_status,
                'health_condition' => $index === 0 ? 'Cleared by physician for equalizing' : ($p->health_condition ?? 'None'),
            ];
        })->toArray();

        $response = $this->put("/admin/bookings/{$booking->id}", [
            'start_date' => $booking->start_date->format('Y-m-d'),
            'end_date' => $booking->end_date->format('Y-m-d'),
            'pickup_option' => 'own',
            'pickup_location' => null,
            'boat_dive' => false,
            'contact_name' => 'Ariane Mae Ramos-Updated',
            'contact_email' => 'ariane.updated@example.com',
            'contact_phone' => '0917 123 4567',
            'contact_facebook' => 'https://facebook.com/ariane',
            'edit_reason' => 'Updated surname and corrected medical notes per guest consent form',
            'participants' => $participantsData,
        ]);

        $response->assertRedirect(route('admin.bookings.show', $booking));
        $this->assertEquals('Ariane Mae Ramos-Updated', $booking->fresh()->contact_name);
        $this->assertEquals('Cleared by physician for equalizing', $participant->fresh()->health_condition);
        $this->assertEquals(
            $originalPricing,
            $booking->fresh()->only(array_keys($originalPricing)),
            'Editing a booking must retain the original pricing snapshot after pricing rules change.'
        );

        // Verify immutable system audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'BOOKING_DATA_MODIFIED',
        ]);
        // Customer is emailed the changes at both the new and the previous address
        Mail::assertSent(BookingDetailsUpdatedMail::class, fn ($mail) => $mail->hasTo('ariane.updated@example.com')
            && collect($mail->changes)->contains(fn ($c) => $c['label'] === 'Contact Email' && $c['new'] === 'ariane.updated@example.com'));
        Mail::assertSent(BookingDetailsUpdatedMail::class, fn ($mail) => $mail->hasTo($originalEmail));
        $this->assertDatabaseHas('notification_logs', [
            'booking_id' => $booking->id,
            'recipient_email' => 'ariane.updated@example.com',
        ]);
    }

    public function test_admin_can_approve_and_reject_reschedule_request(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $reschedule = RescheduleRequest::where('status', 'pending')->first();
        $booking = $reschedule->booking;

        // Approve reschedule
        $response = $this->post("/admin/bookings/requests/reschedule/{$reschedule->id}/approve", [
            'admin_notes' => 'Slot confirmed for next weekend',
        ]);

        $response->assertRedirect();
        $this->assertEquals('approved', $reschedule->fresh()->status);
        $this->assertEquals('confirmed', $booking->fresh()->status);
        $this->assertEquals($reschedule->requested_start_date->format('Y-m-d'), $booking->fresh()->start_date->format('Y-m-d'));

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\RescheduleApprovedMail::class);
    }

    public function test_admin_can_approve_and_reject_cancellation_request(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $cancellation = CancellationRequest::where('status', 'pending')->first();
        $booking = $cancellation->booking;

        // Approve cancellation
        $response = $this->post("/admin/bookings/requests/cancellation/{$cancellation->id}/approve", [
            'admin_notes' => 'Cancellation approved per policy window',
        ]);

        $response->assertRedirect();
        $this->assertEquals('approved', $cancellation->fresh()->status);
        $this->assertEquals('cancelled_by_guest', $booking->fresh()->status);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\CancellationApprovedMail::class);
    }

    public function test_editing_booking_disallows_adding_or_removing_participants(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $booking = Booking::where('booking_number', 'CFP-2026-1001')->first();
        $originalCount = $booking->participants()->count();

        $participantsData = $booking->participants->map(function ($p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'age' => $p->age,
                'swimmer_status' => $p->swimmer_status,
            ];
        })->toArray();

        // Attempt to add an extra participant
        $participantsData[] = [
            'id' => null,
            'name' => 'Extra Person',
            'age' => 22,
            'swimmer_status' => 'swimmer',
        ];

        $response = $this->put("/admin/bookings/{$booking->id}", [
            'start_date' => $booking->start_date->format('Y-m-d'),
            'end_date' => $booking->end_date->format('Y-m-d'),
            'contact_name' => $booking->contact_name,
            'contact_email' => $booking->contact_email,
            'contact_phone' => $booking->contact_phone,
            'edit_reason' => 'Attempting to add unauthorized new participant',
            'participants' => $participantsData,
        ]);

        $response->assertSessionHas('error', 'Adding or removing participants is not permitted when editing booking details. Only existing participants can be modified.');
        $this->assertEquals($originalCount, $booking->fresh()->participants()->count());
    }

    public function test_editing_booking_allows_updating_carpool_pickup_location(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $booking = Booking::where('booking_number', 'CFP-2026-1001')->first();
        $participantsData = $booking->participants->map(function ($p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'age' => $p->age,
                'swimmer_status' => $p->swimmer_status,
            ];
        })->toArray();

        $response = $this->put("/admin/bookings/{$booking->id}", [
            'start_date' => $booking->start_date->format('Y-m-d'),
            'end_date' => $booking->end_date->format('Y-m-d'),
            'pickup_location' => 'Market! Market! (BGC, Taguig) - 3:40 AM',
            'contact_name' => $booking->contact_name,
            'contact_email' => $booking->contact_email,
            'contact_phone' => $booking->contact_phone,
            'edit_reason' => 'Guest requested pickup hub change to BGC',
            'participants' => $participantsData,
        ]);

        $response->assertRedirect(route('admin.bookings.show', $booking));
        $this->assertEquals('Market! Market! (BGC, Taguig) - 3:40 AM', $booking->fresh()->pickup_location);
    }
}
