<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\CoachOpening;
use App\Models\CoachRequest;
use App\Models\ParticipantAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCoachMatchingModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_admin_can_access_coach_roster(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $response = $this->get('/admin/coaches');
        $response->assertStatus(200);
        $response->assertSee('Coach Roster &amp; Schedules', false);
        $response->assertSee('Jose Reyes');
        $response->assertSee('Mary Grace Bautista');
    }

    public function test_coach_role_is_forbidden_from_admin_coaches_module(): void
    {
        $coach = User::where('email', 'coach.jose@campfreedive.ph')->first();
        $this->actingAs($coach);

        $response = $this->get('/admin/coaches');
        $response->assertStatus(403);

        $responseMatching = $this->get('/admin/coaches/matching');
        $responseMatching->assertStatus(403);
    }

    public function test_admin_can_view_coach_detail(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $coachJose = User::where('email', 'coach.jose@campfreedive.ph')->first();

        $response = $this->get("/admin/coaches/{$coachJose->id}");
        $response->assertStatus(200);
        $response->assertSee('Jose Reyes');
    }

    public function test_admin_can_view_students_needing_coach_matching_queue(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $response = $this->get('/admin/coaches/matching');
        $response->assertStatus(200);
        $response->assertSee('Batch Coach Assignment');
    }

    public function test_admin_can_assign_students_to_available_coach(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $unassignedParticipant = BookingParticipant::first();
        $coachMary = User::where('email', 'coach.mary@campfreedive.ph')->first();
        $batch = $unassignedParticipant->booking->batch ?? Batch::first();

        $response = $this->post('/admin/coaches/matching/assign', [
            'participant_ids' => [$unassignedParticipant->id],
            'coach_id' => $coachMary->id,
            'batch_id' => $batch->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('participant_assignments', [
            'participant_id' => $unassignedParticipant->id,
            'coach_id' => $coachMary->id,
            'batch_id' => $batch->id,
            'status' => 'assigned',
        ]);
        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\CoachScheduleNotificationMail::class,
            fn ($mail) => $mail->hasTo($coachMary->email)
        );
    }

    public function test_assigning_over_four_students_records_ratio_override_exception(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $coachJose = User::where('email', 'coach.jose@campfreedive.ph')->first();
        $batch1 = Batch::where('batch_code', 'Batch 1')->first();

        $booking = $batch1->bookings->first() ?: Booking::create([
            'batch_id' => $batch1->id,
            'user_id' => $admin->id,
            'booking_number' => 'BK-' . uniqid(),
            'pin' => '1234',
            'class_type' => 'intro',
            'status' => 'confirmed',
            'total_amount' => 5000,
            'contact_name' => 'Extra Lead',
            'contact_email' => 'lead@example.com',
            'contact_phone' => '09123456789',
            'start_date' => $batch1->start_date,
            'end_date' => $batch1->end_date,
        ]);
        $p1 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Extra Student 1', 'age' => 20, 'price_per_person' => 2500.00]);
        $p2 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Extra Student 2', 'age' => 21, 'price_per_person' => 2500.00]);
        $p3 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Extra Student 3', 'age' => 22, 'price_per_person' => 2500.00]);
        $p4 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Extra Student 4', 'age' => 23, 'price_per_person' => 2500.00]);
        $p5 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Extra Student 5', 'age' => 24, 'price_per_person' => 2500.00]);

        $response = $this->post('/admin/coaches/matching/assign', [
            'participant_ids' => [$p1->id, $p2->id, $p3->id, $p4->id, $p5->id],
            'coach_id' => $coachJose->id,
            'batch_id' => $batch1->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('participant_assignments', [
            'participant_id' => $p5->id,
            'coach_id' => $coachJose->id,
            'is_ratio_override' => true,
        ]);
    }

    public function test_admin_can_reassign_student_with_audit_log(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $coachJose = User::where('email', 'coach.jose@campfreedive.ph')->first();
        $coachMary = User::where('email', 'coach.mary@campfreedive.ph')->first();
        
        $assignment = ParticipantAssignment::where('coach_id', $coachJose->id)->first();
        $participant = $assignment ? $assignment->participant : BookingParticipant::first();

        if (!$assignment) {
            $batch1 = Batch::where('batch_code', 'Batch 1')->first();
            $assignment = ParticipantAssignment::create([
                'participant_id' => $participant->id,
                'booking_id' => $participant->booking_id,
                'coach_id' => $coachJose->id,
                'batch_id' => $batch1->id,
                'dive_date' => $batch1->start_date,
                'assigned_by' => $admin->id,
                'status' => 'assigned',
            ]);
        }

        $response = $this->post("/admin/coaches/{$coachJose->id}/reassign-student", [
            'participant_id' => $participant->id,
            'new_coach_id' => $coachMary->id,
            'reason' => 'Coach Jose requested schedule load reduction.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('participant_assignments', [
            'participant_id' => $participant->id,
            'coach_id' => $coachMary->id,
            'status' => 'assigned',
        ]);

        $this->assertDatabaseHas('assignment_logs', [
            'participant_id' => $participant->id,
            'old_coach_id' => $coachJose->id,
            'new_coach_id' => $coachMary->id,
            'reason' => 'Coach Jose requested schedule load reduction.',
        ]);
    }

    public function test_admin_can_broadcast_open_slot_to_coach_portal(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch = Batch::where('batch_code', 'Batch 2')->first();

        $response = $this->post('/admin/coaches/matching/broadcast', [
            'batch_id' => $batch->id,
            'notes' => 'Open spot for Fundive session.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('coach_openings', [
            'batch_id' => $batch->id,
            'status' => 'open',
            'notes' => 'Open spot for Fundive session.',
        ]);
    }

    public function test_admin_can_review_and_approve_coach_request(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch = Batch::first();
        $coachMark = User::where('email', 'coach.mark@campfreedive.ph')->first();
        $coachChristine = User::where('email', 'coach.christine@campfreedive.ph')->first();

        $opening = CoachOpening::create([
            'batch_id' => $batch->id,
            'dive_date' => $batch->start_date,
            'needed_students_count' => 4,
            'status' => 'open',
            'posted_by' => $admin->id,
            'notes' => 'Test opening',
        ]);

        $requestMark = CoachRequest::create([
            'opening_id' => $opening->id,
            'batch_id' => $batch->id,
            'coach_id' => $coachMark->id,
            'status' => 'pending',
            'notes' => 'Mark available',
        ]);

        $requestChristine = CoachRequest::create([
            'opening_id' => $opening->id,
            'batch_id' => $batch->id,
            'coach_id' => $coachChristine->id,
            'status' => 'pending',
            'notes' => 'Christine available',
        ]);

        $response = $this->post("/admin/coaches/requests/{$requestMark->id}/approve");
        $response->assertRedirect();

        $this->assertEquals('approved', $requestMark->fresh()->status);
        $this->assertEquals('not_selected', $requestChristine->fresh()->status);
        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\CoachScheduleNotificationMail::class,
            fn ($mail) => $mail->hasTo($coachMark->email)
        );
    }

    public function test_admin_can_perform_balanced_batch_assignment_across_multiple_coaches(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch = Batch::where('batch_code', 'Batch 1')->first();
        $coachJose = User::where('email', 'coach.jose@campfreedive.ph')->first();
        $coachMary = User::where('email', 'coach.mary@campfreedive.ph')->first();

        $booking = $batch->bookings->first() ?: Booking::create([
            'batch_id' => $batch->id,
            'user_id' => $admin->id,
            'booking_number' => 'BK-' . uniqid(),
            'pin' => '1234',
            'class_type' => 'intro',
            'status' => 'confirmed',
            'total_amount' => 5000,
            'contact_name' => 'Balance Lead',
            'contact_email' => 'lead@example.com',
            'contact_phone' => '09123456789',
            'start_date' => $batch->start_date,
            'end_date' => $batch->end_date,
        ]);
        $p1 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Balance Test 1', 'age' => 20, 'price_per_person' => 2500]);
        $p2 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Balance Test 2', 'age' => 21, 'price_per_person' => 2500]);
        $p3 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Balance Test 3', 'age' => 22, 'price_per_person' => 2500]);
        $p4 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Balance Test 4', 'age' => 23, 'price_per_person' => 2500]);

        $response = $this->post('/admin/coaches/matching/batch-assign', [
            'batch_id' => $batch->id,
            'assignments' => [
                $coachJose->id => [$p1->id, $p2->id],
                $coachMary->id => [$p3->id, $p4->id],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('participant_assignments', [
            'participant_id' => $p1->id,
            'coach_id' => $coachJose->id,
            'batch_id' => $batch->id,
            'status' => 'assigned',
        ]);
        $this->assertDatabaseHas('participant_assignments', [
            'participant_id' => $p3->id,
            'coach_id' => $coachMary->id,
            'batch_id' => $batch->id,
            'status' => 'assigned',
        ]);
    }

    public function test_batch_assignment_logs_intentional_exception_when_imbalanced_split_is_saved(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch = Batch::where('batch_code', 'Batch 1')->first();
        $coachJose = User::where('email', 'coach.jose@campfreedive.ph')->first();
        $coachMary = User::where('email', 'coach.mary@campfreedive.ph')->first();

        // 3 on Jose, 0 on Mary (imbalanced by 3)
        $booking = $batch->bookings->first() ?: Booking::create([
            'batch_id' => $batch->id,
            'user_id' => $admin->id,
            'booking_number' => 'BK-' . uniqid(),
            'pin' => '1234',
            'class_type' => 'intro',
            'status' => 'confirmed',
            'total_amount' => 5000,
            'contact_name' => 'Imbalance Lead',
            'contact_email' => 'lead@example.com',
            'contact_phone' => '09123456789',
            'start_date' => $batch->start_date,
            'end_date' => $batch->end_date,
        ]);
        $p1 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Imbalance 1', 'age' => 20, 'price_per_person' => 2500]);
        $p2 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Imbalance 2', 'age' => 21, 'price_per_person' => 2500]);
        $p3 = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Imbalance 3', 'age' => 22, 'price_per_person' => 2500]);

        $response = $this->post('/admin/coaches/matching/batch-assign', [
            'batch_id' => $batch->id,
            'assignments' => [
                $coachJose->id => [$p1->id, $p2->id, $p3->id],
                $coachMary->id => [],
            ],
            'exception_note' => 'Coach Mary requested zero load for this weekend due to exam.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'BATCH_COACH_ASSIGNMENT_EXCEPTION',
        ]);
    }

    public function test_admin_can_assign_coaches_to_batch_and_unassign_coach(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch = Batch::where('batch_code', 'Batch 2')->first();
        $coachMary = User::where('email', 'coach.mary@campfreedive.ph')->first();

        $booking = $batch->bookings->first() ?: Booking::create([
            'batch_id' => $batch->id,
            'user_id' => $admin->id,
            'booking_number' => 'BK-' . uniqid(),
            'pin' => '1234',
            'class_type' => 'intro',
            'status' => 'confirmed',
            'total_amount' => 5000,
            'contact_name' => 'Batch 2 Lead',
            'contact_email' => 'lead@example.com',
            'contact_phone' => '09123456789',
            'start_date' => $batch->start_date,
            'end_date' => $batch->end_date,
        ]);
        if ($booking->participants->isEmpty()) {
            BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Batch 2 Student', 'age' => 20, 'price_per_person' => 2500]);
        }

        // 1. Assign coach to batch directly (1-click or multi-coach)
        $response = $this->post('/admin/coaches/matching/assign', [
            'batch_id' => $batch->id,
            'coach_id' => $coachMary->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('participant_assignments', [
            'batch_id' => $batch->id,
            'coach_id' => $coachMary->id,
            'status' => 'assigned',
        ]);

        // 2. Unassign coach from batch
        $responseUnassign = $this->post('/admin/coaches/matching/unassign', [
            'batch_id' => $batch->id,
            'coach_id' => $coachMary->id,
        ]);

        $responseUnassign->assertRedirect();
        $responseUnassign->assertSessionHas('success');

        $this->assertDatabaseMissing('participant_assignments', [
            'batch_id' => $batch->id,
            'coach_id' => $coachMary->id,
            'status' => 'assigned',
        ]);
    }
}
