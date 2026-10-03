<?php

namespace Database\Seeders;

use App\Models\AssignmentLog;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchRiskAssessment;
use App\Models\BatchStatusLog;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingPriceAdjustment;
use App\Models\BookingStatusLog;
use App\Models\CancellationRequest;
use App\Models\CoachAvailability;
use App\Models\CoachOpening;
use App\Models\CoachRequest;
use App\Models\HourlyAssessment;
use App\Models\ManualOverride;
use App\Models\NotificationLog;
use App\Models\ParticipantAssignment;
use App\Models\Payment;
use App\Models\PaymentStatusLog;
use App\Models\PricingRule;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    /**
     * Fixed Carpool Pickup Points strictly matching the booking form process.
     */
    public const PICKUP_MONUMENTO = 'Monumento Hypermarket - 2:30 AM';
    public const PICKUP_TIENDESITAS = 'Shell Tiendesitas - 3:00 AM';
    public const PICKUP_MARKET_MARKET = 'Market Market Taxi Bay - 3:40 AM';
    public const PICKUP_ALABANG = 'Alabang Starmall - 4:15 AM';
    public const PICKUP_STO_TOMAS = 'Sto Tomas Exit - 5:30 AM';

    /**
     * Seed the application's database with authentic Camp Freedive PH operational data.
     */
    public function run(): void
    {
        // =========================================================================
        // 0. CLEAN RESET OF ALL PRODUCTION & OPERATIONAL TABLES
        // =========================================================================
        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        $tables = [
            'audit_logs',
            'users',
            'batches',
            'batch_status_logs',
            'bookings',
            'booking_participants',
            'booking_price_adjustments',
            'booking_status_logs',
            'cancellation_requests',
            'reschedule_requests',
            'refund_requests',
            'payments',
            'payment_status_logs',
            'participant_assignments',
            'assignment_logs',
            'assignment_release_requests',
            'coach_availabilities',
            'coach_openings',
            'coach_requests',
            'pricing_rules',
            'batch_risk_assessments',
            'hourly_assessments',
            'manual_overrides',
            'notification_logs',
            'system_settings',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        // =========================================================================
        // 1. SEED AUTHENTIC STAFF & COACH USERS
        // =========================================================================
        
        // 1. Camp Owner & Founder (Antonio Mercado)
        $owner = User::create([
            'name' => 'Antonio Mercado',
            'email' => 'owner@campfreedive.ph',
            'phone' => '0927 887 9894',
            'password' => Hash::make('Password123!'),
            'role' => 'owner',
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        // 2. Lead Camp Admin & Operations Coordinator (Maria Santos)
        $admin = User::create([
            'name' => 'Maria Santos',
            'email' => 'admin@campfreedive.ph',
            'phone' => '0917 888 1234',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);


        // 3. Senior Freediving Coach (Jose Reyes - AIDA 4 / Molchanovs W2)
        $coachJose = User::create([
            'name' => 'Jose Reyes',
            'email' => 'coach.jose@campfreedive.ph',
            'phone' => '0919 456 7890',
            'password' => Hash::make('Password123!'),
            'role' => 'coach',
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        // 4. Freediving Coach (Mary Grace Bautista - Wave 2 Coach)
        $coachMary = User::create([
            'name' => 'Mary Grace Bautista',
            'email' => 'coach.mary@campfreedive.ph',
            'phone' => '0920 111 2233',
            'password' => Hash::make('Password123!'),
            'role' => 'coach',
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        // 5. Freediving Coach & Safety Diver (Michael Cruz - AIDA 3)
        $coachMichael = User::create([
            'name' => 'Michael Cruz',
            'email' => 'coach.michael@campfreedive.ph',
            'phone' => '0917 555 4321',
            'password' => Hash::make('Password123!'),
            'role' => 'coach',
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        // 6. Freediving Coach (Christine Villamayor - Equalization Coach)
        $coachChristine = User::create([
            'name' => 'Christine Villamayor',
            'email' => 'coach.christine@campfreedive.ph',
            'phone' => '0918 333 7788',
            'password' => Hash::make('Password123!'),
            'role' => 'coach',
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        // 7. Freediving Coach (Mark Garcia - Wave 1 Coach)
        $coachMark = User::create([
            'name' => 'Mark Garcia',
            'email' => 'coach.mark@campfreedive.ph',
            'phone' => '0922 444 5566',
            'password' => Hash::make('Password123!'),
            'role' => 'coach',
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        // 8. Inactive Coach (Angelo Fernandez - Leave of Absence)
        $coachAngelo = User::create([
            'name' => 'Angelo Fernandez',
            'email' => 'coach.angelo@campfreedive.ph',
            'phone' => '0999 000 1111',
            'password' => Hash::make('Password123!'),
            'role' => 'coach',
            'status' => 'inactive',
            'must_change_password' => false,
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        // Log Initial Account Provisioning Audits
        AuditLogger::log('USER_CREATED', 'Founder & Owner account initialized: Antonio Mercado (owner@campfreedive.ph)', $owner, 'System Seeder');
        AuditLogger::log('USER_CREATED', 'Admin Coordinator provisioned: Maria Santos (admin@campfreedive.ph)', $admin, 'Antonio Mercado');
        AuditLogger::log('USER_CREATED', 'Coach provisioned: Jose Reyes (coach.jose@campfreedive.ph)', $coachJose, 'Maria Santos');
        AuditLogger::log('USER_CREATED', 'Coach provisioned: Mary Grace Bautista (coach.mary@campfreedive.ph)', $coachMary, 'Maria Santos');
        AuditLogger::log('USER_CREATED', 'Coach provisioned: Michael Cruz (coach.michael@campfreedive.ph)', $coachMichael, 'Maria Santos');
        AuditLogger::log('USER_CREATED', 'Coach provisioned: Christine Villamayor (coach.christine@campfreedive.ph)', $coachChristine, 'Maria Santos');
        AuditLogger::log('USER_CREATED', 'Coach provisioned: Mark Garcia (coach.mark@campfreedive.ph)', $coachMark, 'Maria Santos');
        AuditLogger::log('USER_STATUS_TOGGLED', 'Staff account marked inactive (Leave of Absence): Angelo Fernandez', $coachAngelo, 'Antonio Mercado');

        // =========================================================================
        // 2. SEED REALISTIC 2D1N DIVE BATCHES IN STRICT CHRONOLOGICAL ORDER
        // Batch 1 is strictly the earliest date, progressing sequentially to future batches.
        // =========================================================================
        $upcomingSat = Carbon::now()->next(Carbon::SATURDAY)->startOfDay();

        // -------------------------------------------------------------------------
        // BATCH 1: Earliest Past Batch (-28 Days / 4 Weeks Ago) -> Completed
        // -------------------------------------------------------------------------
        $batch1Start = $upcomingSat->copy()->subDays(28);
        $batch1End = $batch1Start->copy()->addDay();
        $batch1 = Batch::create([
            'name' => 'Batch 1',
            'batch_code' => 'Batch 1',
            'start_date' => $batch1Start,
            'end_date' => $batch1End,
            'status' => 'completed',
            'lifecycle_status' => 'completed',
            'risk_classification' => 'very_safe',
            'capacity_note' => 'Season Opener Discovery Camp (Mabini Coastline)',
            'notes' => 'Early season kickoff. Anilao Marine Sanctuary line & depth orientation.',
            'completed_at' => $batch1End->copy()->addDay(),
            'created_by' => $admin->id,
        ]);
        BatchStatusLog::create([
            'batch_id' => $batch1->id,
            'old_status' => 'confirmed',
            'new_status' => 'completed',
            'changed_by' => $admin->id,
            'note' => 'Trip safely concluded. All divers certified.',
            'created_at' => $batch1End->copy()->addDay(),
        ]);

        // -------------------------------------------------------------------------
        // BATCH 2: Past Batch (-21 Days / 3 Weeks Ago) -> Completed
        // -------------------------------------------------------------------------
        $batch2Start = $upcomingSat->copy()->subDays(21);
        $batch2End = $batch2Start->copy()->addDay();
        $batch2 = Batch::create([
            'name' => 'Batch 2',
            'batch_code' => 'Batch 2',
            'start_date' => $batch2Start,
            'end_date' => $batch2End,
            'status' => 'completed',
            'lifecycle_status' => 'completed',
            'risk_classification' => 'very_safe',
            'capacity_note' => 'Discovery & Open Water Training Camp',
            'notes' => 'Twin Rocks & Cathedral Rock marine sanctuary sessions.',
            'completed_at' => $batch2End->copy()->addDay(),
            'created_by' => $admin->id,
        ]);
        BatchStatusLog::create([
            'batch_id' => $batch2->id,
            'old_status' => 'confirmed',
            'new_status' => 'completed',
            'changed_by' => $admin->id,
            'note' => 'All participants returned safely. Batch logged as completed.',
            'created_at' => $batch2End->copy()->addDay(),
        ]);

        // -------------------------------------------------------------------------
        // BATCH 3: Past Batch (-14 Days / 2 Weeks Ago) -> Cancelled by Camp (Storm Advisory)
        // -------------------------------------------------------------------------
        $batch3Start = $upcomingSat->copy()->subDays(14);
        $batch3End = $batch3Start->copy()->addDay();
        $batch3 = Batch::create([
            'name' => 'Batch 3',
            'batch_code' => 'Batch 3',
            'start_date' => $batch3Start,
            'end_date' => $batch3End,
            'status' => 'cancelled_by_camp',
            'lifecycle_status' => 'cancelled_by_camp',
            'risk_classification' => 'critical_risk',
            'cancelled_at' => $batch3Start->copy()->subDays(2),
            'cancellation_reason' => 'PAGASA Heavy Rainfall Warning & PCG Sea Travel Gale Advisory.',
            'capacity_note' => 'Monsoon Storm Advisory Rebooking',
            'notes' => 'Camp cancelled due to extreme monsoon squalls and gale warnings. All guests rebooked or refunded.',
            'created_by' => $owner->id,
        ]);
        BatchStatusLog::create([
            'batch_id' => $batch3->id,
            'old_status' => 'confirmed',
            'new_status' => 'cancelled_by_camp',
            'changed_by' => $owner->id,
            'note' => 'Cancelled by Camp due to PCG Gale Warning & PAGASA Southwest Monsoon advisory.',
            'created_at' => $batch3Start->copy()->subDays(2),
        ]);

        // -------------------------------------------------------------------------
        // BATCH 4: Past Batch (-7 Days / 1 Week Ago) -> Completed
        // -------------------------------------------------------------------------
        $batch4Start = $upcomingSat->copy()->subDays(7);
        $batch4End = $batch4Start->copy()->addDay();
        $batch4 = Batch::create([
            'name' => 'Batch 4',
            'batch_code' => 'Batch 4',
            'start_date' => $batch4Start,
            'end_date' => $batch4End,
            'status' => 'completed',
            'lifecycle_status' => 'completed',
            'risk_classification' => 'very_safe',
            'capacity_note' => 'Weekend Discovery Camp (Mabini Coastline)',
            'notes' => 'Successful 2D1N trip in Mabini with 100% student certification rate.',
            'completed_at' => $batch4End->copy()->addDay(),
            'created_by' => $admin->id,
        ]);
        BatchStatusLog::create([
            'batch_id' => $batch4->id,
            'old_status' => 'confirmed',
            'new_status' => 'completed',
            'changed_by' => $admin->id,
            'note' => 'All participants safely returned to Manila. Batch logged as completed.',
            'created_at' => $batch4End->copy()->addDay(),
        ]);

        // -------------------------------------------------------------------------
        // BATCH 5: Upcoming Weekend (Current Active Departure) -> Confirmed
        // -------------------------------------------------------------------------
        $batch5Start = $upcomingSat->copy();
        $batch5End = $batch5Start->copy()->addDay();
        $batch5 = Batch::create([
            'name' => 'Batch 5',
            'batch_code' => 'Batch 5',
            'start_date' => $batch5Start,
            'end_date' => $batch5End,
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
            'risk_classification' => 'very_safe',
            'capacity_note' => 'Weekend Discovery & Line Training Camp',
            'notes' => 'Anilao Marine Sanctuary Discovery & Line Training. High student turnout.',
            'created_by' => $admin->id,
        ]);
        BatchStatusLog::create([
            'batch_id' => $batch5->id,
            'old_status' => null,
            'new_status' => 'confirmed',
            'changed_by' => $admin->id,
            'note' => 'Batch created and confirmed for upcoming weekend departure.',
            'created_at' => now()->subDays(6),
        ]);

        // -------------------------------------------------------------------------
        // BATCH 6: Next Weekend (+7 Days) -> Confirmed, Open for Matching
        // -------------------------------------------------------------------------
        $batch6Start = $upcomingSat->copy()->addDays(7);
        $batch6End = $batch6Start->copy()->addDay();
        $batch6 = Batch::create([
            'name' => 'Batch 6',
            'batch_code' => 'Batch 6',
            'start_date' => $batch6Start,
            'end_date' => $batch6End,
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
            'risk_classification' => 'safe',
            'capacity_note' => 'Beginner Friendly Weekend (Max 45 Pax)',
            'notes' => 'Cathedral Rock & Twin Rocks Marine Reserve dive spots.',
            'created_by' => $admin->id,
        ]);
        BatchStatusLog::create([
            'batch_id' => $batch6->id,
            'old_status' => null,
            'new_status' => 'confirmed',
            'changed_by' => $admin->id,
            'note' => 'Batch opened for advance reservations.',
            'created_at' => now()->subDays(4),
        ]);

        // -------------------------------------------------------------------------
        // BATCH 7: Future Weekend (+14 Days) -> Confirmed
        // -------------------------------------------------------------------------
        $batch7Start = $upcomingSat->copy()->addDays(14);
        $batch7End = $batch7Start->copy()->addDay();
        $batch7 = Batch::create([
            'name' => 'Batch 7',
            'batch_code' => 'Batch 7',
            'start_date' => $batch7Start,
            'end_date' => $batch7End,
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
            'risk_classification' => 'safe',
            'capacity_note' => 'Depth & Freefall Workshop',
            'notes' => 'Specialized depth training and Frenzel equalization clinic.',
            'created_by' => $admin->id,
        ]);
        BatchStatusLog::create([
            'batch_id' => $batch7->id,
            'old_status' => null,
            'new_status' => 'confirmed',
            'changed_by' => $admin->id,
            'note' => 'Scheduled advance weekend session.',
            'created_at' => now()->subDays(2),
        ]);

        // -------------------------------------------------------------------------
        // BATCH 8: Future Weekend (+21 Days) -> Rescheduled
        // -------------------------------------------------------------------------
        $batch8Start = $upcomingSat->copy()->addDays(21);
        $batch8End = $batch8Start->copy()->addDay();
        $batch8 = Batch::create([
            'name' => 'Batch 8',
            'batch_code' => 'Batch 8',
            'start_date' => $batch8Start,
            'end_date' => $batch8End,
            'status' => 'rescheduled',
            'lifecycle_status' => 'rescheduled',
            'risk_classification' => 'moderate',
            'capacity_note' => 'Resort facility maintenance rollover',
            'notes' => 'Shifted date to accommodate resort dock and compressor maintenance.',
            'created_by' => $owner->id,
        ]);
        BatchStatusLog::create([
            'batch_id' => $batch8->id,
            'old_status' => 'confirmed',
            'new_status' => 'rescheduled',
            'changed_by' => $owner->id,
            'note' => 'Moved batch dates to accommodate resort dock maintenance.',
            'created_at' => now()->subDay(),
        ]);

        // -------------------------------------------------------------------------
        // BATCH 9: Future Weekend (+28 Days) -> Open for Booking
        // -------------------------------------------------------------------------
        $batch9Start = $upcomingSat->copy()->addDays(28);
        $batch9End = $batch9Start->copy()->addDay();
        $batch9 = Batch::create([
            'name' => 'Batch 9',
            'batch_code' => 'Batch 9',
            'start_date' => $batch9Start,
            'end_date' => $batch9End,
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
            'risk_classification' => 'very_safe',
            'capacity_note' => 'Advance Open Water & Line Training Camp',
            'notes' => 'Open for online reservations across all courses.',
            'created_by' => $admin->id,
        ]);
        BatchStatusLog::create([
            'batch_id' => $batch9->id,
            'old_status' => null,
            'new_status' => 'confirmed',
            'changed_by' => $admin->id,
            'note' => 'Published 4-week advance booking schedule.',
            'created_at' => now()->subDay(),
        ]);

        // =========================================================================
        // 3. SEED COACH AVAILABILITIES (COACH PORTAL CALENDAR)
        // =========================================================================

        // Jose Reyes: Assigned on Batch 5 (Sept 12 & 13), Available on Batch 6 (Sept 19 & 20)
        foreach ([$batch5Start, $batch5End] as $d) {
            CoachAvailability::create([
                'coach_id' => $coachJose->id,
                'date' => $d->format('Y-m-d'),
                'status' => 'assigned',
                'notes' => 'Assigned lead instructor for Batch 5 Discovery Group',
            ]);
        }
        foreach ([$batch6Start, $batch6End] as $d) {
            CoachAvailability::create([
                'coach_id' => $coachJose->id,
                'date' => $d->format('Y-m-d'),
                'status' => 'available',
                'notes' => 'Available for weekend departure',
            ]);
        }

        // Mary Grace Bautista: Assigned on Batch 5 (Sept 12 & 13), Available on Batch 6 (Sept 19 & 20)
        foreach ([$batch5Start, $batch5End] as $d) {
            CoachAvailability::create([
                'coach_id' => $coachMary->id,
                'date' => $d->format('Y-m-d'),
                'status' => 'assigned',
                'notes' => 'Assigned instructor for Batch 5 Open Water Group',
            ]);
        }
        foreach ([$batch6Start, $batch6End] as $d) {
            CoachAvailability::create([
                'coach_id' => $coachMary->id,
                'date' => $d->format('Y-m-d'),
                'status' => 'available',
                'notes' => 'Available for beginner sessions',
            ]);
        }

        // Michael Cruz: Assigned on Batch 5 (Sept 12 & 13), Unavailable on Batch 6 (Sept 19 & 20)
        foreach ([$batch5Start, $batch5End] as $d) {
            CoachAvailability::create([
                'coach_id' => $coachMichael->id,
                'date' => $d->format('Y-m-d'),
                'status' => 'assigned',
                'notes' => 'Assigned safety & fundive coach for Batch 5',
            ]);
        }
        foreach ([$batch6Start, $batch6End] as $d) {
            CoachAvailability::create([
                'coach_id' => $coachMichael->id,
                'date' => $d->format('Y-m-d'),
                'status' => 'unavailable',
                'notes' => 'Attending CPR/First Aid Renewal Seminar',
            ]);
        }

        // Christine Villamayor: Available on Batch 5 (Sept 12 & 13) & Batch 6 (Sept 19 & 20)
        foreach ([$batch5Start, $batch5End] as $d) {
            CoachAvailability::create([
                'coach_id' => $coachChristine->id,
                'date' => $d->format('Y-m-d'),
                'status' => 'available',
                'notes' => 'Available for standby or private coaching',
            ]);
        }
        foreach ([$batch6Start, $batch6End] as $d) {
            CoachAvailability::create([
                'coach_id' => $coachChristine->id,
                'date' => $d->format('Y-m-d'),
                'status' => 'available',
                'notes' => 'Available for Batch 6 coaching roster',
            ]);
        }

        // Mark Garcia: Available on Batch 6 (Sept 19 & 20) & Batch 7 (Sept 26 & 27)
        foreach ([$batch6Start, $batch6End] as $d) {
            CoachAvailability::create([
                'coach_id' => $coachMark->id,
                'date' => $d->format('Y-m-d'),
                'status' => 'available',
                'notes' => 'Ready for assignment in Matching Queue',
            ]);
        }
        foreach ([$batch7Start, $batch7End] as $d) {
            CoachAvailability::create([
                'coach_id' => $coachMark->id,
                'date' => $d->format('Y-m-d'),
                'status' => 'available',
                'notes' => 'Available for depth clinic',
            ]);
        }

        // =========================================================================
        // 4. SEED AUTHENTIC CUSTOMER BOOKINGS & PARTICIPANTS
        // Strictly using the 5 exact fixed pickup points from the booking form!
        // =========================================================================

        // -------------------------------------------------------------------------
        // BOOKING 1: Discovery Class (3 Pax) - Lead: Juan Dela Cruz -> Batch 5
        // Pickup: Shell Tiendesitas - 3:00 AM
        // -------------------------------------------------------------------------
        $b1 = Booking::create([
            'batch_id' => $batch5->id,
            'booking_number' => 'CFP-2026-1001',
            'pin' => '1001',
            'class_type' => 'discovery',
            'is_certified_diver' => false,
            'start_date' => $batch5Start,
            'end_date' => $batch5End,
            'pickup_option' => 'carpool',
            'pickup_location' => self::PICKUP_TIENDESITAS,
            'carpool_fee' => 3000.00,
            'boat_dive' => true,
            'boat_dive_fee' => 2400.00,
            'lgu_fee' => 900.00,
            'environmental_fee' => 150.00,
            'subtotal' => 12750.00,
            'total_amount' => 19200.00,
            'downpayment_amount' => 9000.00,
            'balance_amount' => 10200.00,
            'contact_name' => 'Juan Dela Cruz',
            'contact_email' => 'juan.delacruz@gmail.com',
            'contact_phone' => '0917 123 4567',
            'contact_facebook' => 'https://facebook.com/juandelacruz.ph',
            'status' => 'confirmed',
        ]);

        $p1_1 = BookingParticipant::create([
            'booking_id' => $b1->id,
            'name' => 'Juan Dela Cruz',
            'age' => 27,
            'health_condition' => 'Fit for diving, no declared medical issues.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);
        $p1_2 = BookingParticipant::create([
            'booking_id' => $b1->id,
            'name' => 'Sarah Aquino',
            'age' => 26,
            'health_condition' => 'Mild ear pressure sensitivity, practicing equalizing.',
            'swimmer_status' => 'non_swimmer',
            'price_per_person' => 4250.00,
        ]);
        $p1_3 = BookingParticipant::create([
            'booking_id' => $b1->id,
            'name' => 'John Paul Mendoza',
            'age' => 28,
            'health_condition' => 'Fit for diving.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);

        // Assign Booking 1 students to Senior Coach Jose Reyes
        foreach ([$p1_1, $p1_2, $p1_3] as $p) {
            ParticipantAssignment::create([
                'participant_id' => $p->id,
                'booking_id' => $b1->id,
                'coach_id' => $coachJose->id,
                'batch_id' => $batch5->id,
                'dive_date' => $batch5Start,
                'assigned_by' => $admin->id,
                'assigned_at' => now()->subDays(4),
                'status' => 'assigned',
                'is_ratio_override' => false,
            ]);
        }

        Payment::create([
            'booking_id' => $b1->id,
            'payment_method' => 'gcash',
            'transaction_id' => 'PAYM-20260831-GCASH-9821',
            'paymongo_payment_id' => 'pay_live_8xK2mP9vL1qN',
            'paymongo_resource_id' => 'src_live_9kL2pQ8x',
            'amount' => 9000.00,
            'fee_amount' => 225.00,
            'net_amount' => 8775.00,
            'payment_type' => 'downpayment',
            'status' => 'completed',
            'paid_at' => now()->subDays(4),
        ]);

        BookingStatusLog::create([
            'booking_id' => $b1->id,
            'old_status' => 'new',
            'new_status' => 'confirmed',
            'changed_by' => null,
            'note' => 'Online customer reservation confirmed with GCash downpayment of ₱9,000. Remaining balance: ₱10,200.',
            'created_at' => now()->subDays(4),
        ]);

        // -------------------------------------------------------------------------
        // BOOKING 2: Discovery Class (2 Pax) - Lead: Robert Gonzales -> Batch 5
        // Pickup: Own Transportation
        // -------------------------------------------------------------------------
        $b2 = Booking::create([
            'batch_id' => $batch5->id,
            'booking_number' => 'CFP-2026-1002',
            'pin' => '1002',
            'class_type' => 'discovery',
            'is_certified_diver' => false,
            'start_date' => $batch5Start,
            'end_date' => $batch5End,
            'pickup_option' => 'own',
            'pickup_location' => null,
            'carpool_fee' => 0.00,
            'boat_dive' => true,
            'boat_dive_fee' => 1600.00,
            'lgu_fee' => 600.00,
            'environmental_fee' => 100.00,
            'subtotal' => 8500.00,
            'total_amount' => 10800.00,
            'downpayment_amount' => 10800.00,
            'balance_amount' => 0.00,
            'contact_name' => 'Robert Gonzales',
            'contact_email' => 'robert.gonzales@yahoo.com',
            'contact_phone' => '0918 555 9876',
            'contact_facebook' => 'https://facebook.com/robert.gonzales',
            'status' => 'confirmed',
        ]);

        $p2_1 = BookingParticipant::create([
            'booking_id' => $b2->id,
            'name' => 'Robert Gonzales',
            'age' => 31,
            'health_condition' => 'Cleared medical waiver. Swimmer with open water experience.',
            'swimmer_status' => 'confident_swimmer',
            'price_per_person' => 4250.00,
        ]);
        $p2_2 = BookingParticipant::create([
            'booking_id' => $b2->id,
            'name' => 'Stephanie De Leon',
            'age' => 29,
            'health_condition' => 'None declared.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);

        // Assign Booking 2 students to Coach Mary Grace Bautista
        foreach ([$p2_1, $p2_2] as $p) {
            ParticipantAssignment::create([
                'participant_id' => $p->id,
                'booking_id' => $b2->id,
                'coach_id' => $coachMary->id,
                'batch_id' => $batch5->id,
                'dive_date' => $batch5Start,
                'assigned_by' => $admin->id,
                'assigned_at' => now()->subDays(3),
                'status' => 'assigned',
                'is_ratio_override' => false,
            ]);
        }

        Payment::create([
            'booking_id' => $b2->id,
            'payment_method' => 'bpi_bank_transfer',
            'transaction_id' => 'PAYM-20260831-BPI-4412',
            'paymongo_payment_id' => 'pay_live_7mN3xP1vQ5wR',
            'amount' => 10800.00,
            'fee_amount' => 0.00,
            'net_amount' => 10800.00,
            'payment_type' => 'full_payment',
            'status' => 'completed',
            'paid_at' => now()->subDays(3),
        ]);

        BookingStatusLog::create([
            'booking_id' => $b2->id,
            'old_status' => 'new',
            'new_status' => 'confirmed',
            'changed_by' => null,
            'note' => 'Full payment received via BPI Direct Transfer (₱10,800.00). Assigned to Coach Mary Grace Bautista.',
            'created_at' => now()->subDays(3),
        ]);

        // -------------------------------------------------------------------------
        // BOOKING 3: Fun Dive & Line Training (1 Pax) - Lead: David Lim -> Batch 5
        // Pickup: Market Market Taxi Bay - 3:40 AM
        // -------------------------------------------------------------------------
        $b3 = Booking::create([
            'batch_id' => $batch5->id,
            'booking_number' => 'CFP-2026-1003',
            'pin' => '1003',
            'class_type' => 'fundive',
            'is_certified_diver' => true,
            'start_date' => $batch5Start,
            'end_date' => $batch5End,
            'pickup_option' => 'carpool',
            'pickup_location' => self::PICKUP_MARKET_MARKET,
            'carpool_fee' => 1000.00,
            'boat_dive' => true,
            'boat_dive_fee' => 800.00,
            'lgu_fee' => 300.00,
            'environmental_fee' => 50.00,
            'subtotal' => 2500.00,
            'total_amount' => 4650.00,
            'downpayment_amount' => 4650.00,
            'balance_amount' => 0.00,
            'contact_name' => 'David Lim',
            'contact_email' => 'david.lim@outlook.com',
            'contact_phone' => '0920 333 4455',
            'contact_facebook' => 'https://facebook.com/davidlim.freedive',
            'status' => 'confirmed',
        ]);

        $p3_1 = BookingParticipant::create([
            'booking_id' => $b3->id,
            'name' => 'David Lim',
            'age' => 33,
            'health_condition' => 'Certified AIDA 2 Diver (PB: 28m). Bringing personal carbon fins & dive computer.',
            'swimmer_status' => 'confident_swimmer',
            'price_per_person' => 2500.00,
        ]);

        // Assign to Coach Michael Cruz
        ParticipantAssignment::create([
            'participant_id' => $p3_1->id,
            'booking_id' => $b3->id,
            'coach_id' => $coachMichael->id,
            'batch_id' => $batch5->id,
            'dive_date' => $batch5Start,
            'assigned_by' => $admin->id,
            'assigned_at' => now()->subDays(2),
            'status' => 'assigned',
            'is_ratio_override' => false,
        ]);

        Payment::create([
            'booking_id' => $b3->id,
            'payment_method' => 'maya',
            'transaction_id' => 'PAYM-20260831-MAYA-6621',
            'paymongo_payment_id' => 'pay_live_3xW8vM2kP9qT',
            'amount' => 4650.00,
            'fee_amount' => 116.25,
            'net_amount' => 4533.75,
            'payment_type' => 'full_payment',
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
        ]);

        BookingStatusLog::create([
            'booking_id' => $b3->id,
            'old_status' => 'new',
            'new_status' => 'confirmed',
            'changed_by' => null,
            'note' => 'Certified fundiver confirmed with full payment via Maya.',
            'created_at' => now()->subDays(2),
        ]);

        // -------------------------------------------------------------------------
        // BOOKING 4: Discovery Class (4 Pax) - Lead: Joseph Tan -> Batch 6
        // Pickup: Monumento Hypermarket - 2:30 AM (In Matching Queue for Batch 6)
        // -------------------------------------------------------------------------
        $b4 = Booking::create([
            'batch_id' => $batch6->id,
            'booking_number' => 'CFP-2026-1004',
            'pin' => '1004',
            'class_type' => 'discovery',
            'is_certified_diver' => false,
            'start_date' => $batch6Start,
            'end_date' => $batch6End,
            'pickup_option' => 'carpool',
            'pickup_location' => self::PICKUP_MONUMENTO,
            'carpool_fee' => 4000.00,
            'boat_dive' => true,
            'boat_dive_fee' => 3200.00,
            'lgu_fee' => 1200.00,
            'environmental_fee' => 200.00,
            'subtotal' => 17000.00,
            'total_amount' => 25600.00,
            'downpayment_amount' => 12000.00,
            'balance_amount' => 13600.00,
            'contact_name' => 'Joseph Tan',
            'contact_email' => 'joseph.tan@gmail.com',
            'contact_phone' => '0919 777 8899',
            'contact_facebook' => 'https://facebook.com/joseph.tan.ph',
            'status' => 'confirmed',
        ]);

        BookingParticipant::create([
            'booking_id' => $b4->id,
            'name' => 'Joseph Tan',
            'age' => 28,
            'health_condition' => 'Fit for diving.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);
        BookingParticipant::create([
            'booking_id' => $b4->id,
            'name' => 'Michelle Castro',
            'age' => 27,
            'health_condition' => 'None declared.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);
        BookingParticipant::create([
            'booking_id' => $b4->id,
            'name' => 'Christopher Ramos',
            'age' => 30,
            'health_condition' => 'First time freediving.',
            'swimmer_status' => 'non_swimmer',
            'price_per_person' => 4250.00,
        ]);
        BookingParticipant::create([
            'booking_id' => $b4->id,
            'name' => 'Princess Dela Rosa',
            'age' => 25,
            'health_condition' => 'Fit for diving.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);

        Payment::create([
            'booking_id' => $b4->id,
            'payment_method' => 'gcash',
            'transaction_id' => 'PAYM-20260831-GCASH-3319',
            'paymongo_payment_id' => 'pay_live_2kL9mP4vQ8wZ',
            'amount' => 12000.00,
            'fee_amount' => 300.00,
            'net_amount' => 11700.00,
            'payment_type' => 'downpayment',
            'status' => 'completed',
            'paid_at' => now()->subDay(),
        ]);

        BookingStatusLog::create([
            'booking_id' => $b4->id,
            'old_status' => 'new',
            'new_status' => 'confirmed',
            'changed_by' => null,
            'note' => 'Group booking confirmed with ₱12,000 GCash downpayment. In coach matching queue for Batch 6.',
            'created_at' => now()->subDay(),
        ]);

        // -------------------------------------------------------------------------
        // BOOKING 5: Refinement Class (2 Pax) - Lead: Richard Soriano -> Reschedule Requested
        // Pickup: Alabang Starmall - 4:15 AM
        // -------------------------------------------------------------------------
        $b5Start = $batch5Start->copy()->addDays(5);
        $b5 = Booking::create([
            'batch_id' => null,
            'booking_number' => 'CFP-2026-1005',
            'pin' => '1005',
            'class_type' => 'refinement',
            'is_certified_diver' => false,
            'start_date' => $b5Start,
            'end_date' => $b5Start->copy()->addDay(),
            'pickup_option' => 'carpool',
            'pickup_location' => self::PICKUP_ALABANG,
            'carpool_fee' => 2000.00,
            'boat_dive' => true,
            'boat_dive_fee' => 1600.00,
            'lgu_fee' => 600.00,
            'environmental_fee' => 100.00,
            'subtotal' => 8200.00,
            'total_amount' => 12500.00,
            'downpayment_amount' => 6000.00,
            'balance_amount' => 6500.00,
            'contact_name' => 'Richard Soriano',
            'contact_email' => 'richard.soriano@gmail.com',
            'contact_phone' => '0917 222 3344',
            'contact_facebook' => 'https://facebook.com/richard.soriano',
            'status' => 'reschedule_requested',
        ]);

        BookingParticipant::create([
            'booking_id' => $b5->id,
            'name' => 'Richard Soriano',
            'age' => 32,
            'health_condition' => 'Working on Frenzel equalization technique.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4100.00,
        ]);
        BookingParticipant::create([
            'booking_id' => $b5->id,
            'name' => 'Christian Santiago',
            'age' => 30,
            'health_condition' => 'Fit for diving.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4100.00,
        ]);

        Payment::create([
            'booking_id' => $b5->id,
            'payment_method' => 'gcash',
            'transaction_id' => 'PAYM-20260831-GCASH-1105',
            'paymongo_payment_id' => 'pay_live_5vM1kP7xQ3wL',
            'amount' => 6000.00,
            'fee_amount' => 150.00,
            'net_amount' => 5850.00,
            'payment_type' => 'downpayment',
            'status' => 'completed',
            'paid_at' => now()->subDays(5),
        ]);

        RescheduleRequest::create([
            'booking_id' => $b5->id,
            'current_start_date' => $b5Start,
            'current_end_date' => $b5Start->copy()->addDay(),
            'requested_start_date' => $b5Start->copy()->addDays(7),
            'requested_end_date' => $b5Start->copy()->addDays(8),
            'reason' => 'Flight back to Manila was delayed due to bad weather; requesting move to the following weekend.',
            'status' => 'pending',
        ]);

        BookingStatusLog::create([
            'booking_id' => $b5->id,
            'old_status' => 'confirmed',
            'new_status' => 'reschedule_requested',
            'changed_by' => null,
            'note' => 'Guest submitted reschedule request to shift to next weekend: ' . $b5Start->copy()->addDays(7)->format('M d, Y') . '.',
            'created_at' => now()->subHours(4),
        ]);

        // -------------------------------------------------------------------------
        // BOOKING 6: Discovery Class (1 Pax) - Lead: Sarah Aquino -> Cancellation & 100% Policy Refund
        // Pickup: Sto Tomas Exit - 5:30 AM
        // -------------------------------------------------------------------------
        $b6Start = Carbon::now()->addDays(18)->startOfDay(); // > 14 days out -> 100% Eligible
        $b6 = Booking::create([
            'batch_id' => null,
            'booking_number' => 'CFP-2026-1006',
            'pin' => '1006',
            'class_type' => 'discovery',
            'is_certified_diver' => false,
            'start_date' => $b6Start,
            'end_date' => $b6Start->copy()->addDay(),
            'pickup_option' => 'carpool',
            'pickup_location' => self::PICKUP_STO_TOMAS,
            'carpool_fee' => 1000.00,
            'boat_dive' => true,
            'boat_dive_fee' => 800.00,
            'lgu_fee' => 300.00,
            'environmental_fee' => 50.00,
            'subtotal' => 4250.00,
            'total_amount' => 6400.00,
            'downpayment_amount' => 3000.00,
            'balance_amount' => 3400.00,
            'contact_name' => 'Sarah Aquino',
            'contact_email' => 'sarah.aquino@yahoo.com',
            'contact_phone' => '0922 666 7788',
            'status' => 'cancellation_requested',
        ]);

        BookingParticipant::create([
            'booking_id' => $b6->id,
            'name' => 'Sarah Aquino',
            'age' => 26,
            'health_condition' => 'Ear infection diagnosis.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);

        $p6 = Payment::create([
            'booking_id' => $b6->id,
            'payment_method' => 'gcash',
            'transaction_id' => 'PAYM-20260831-GCASH-7741',
            'paymongo_payment_id' => 'pay_live_6wN2mP8xQ1vK',
            'amount' => 3000.00,
            'fee_amount' => 75.00,
            'net_amount' => 2925.00,
            'payment_type' => 'downpayment',
            'status' => 'refund_requested',
            'paid_at' => now()->subDays(6),
        ]);

        CancellationRequest::create([
            'booking_id' => $b6->id,
            'calculated_refund_amount' => 3000.00,
            'reason' => 'Medical advisory: Ear infection; physician advised to avoid depth and water submersion.',
            'force_majeure_flag' => false,
            'status' => 'pending',
        ]);

        RefundRequest::create([
            'payment_id' => $p6->id,
            'booking_id' => $b6->id,
            'requested_by' => 'customer',
            'requested_at' => now()->subHours(3),
            'eligibility_calculated' => [
                'days_until_dive' => 18,
                'eligible_for_refund' => true,
                'refund_percentage' => 100,
                'window_label' => 'Standard Notice Window (> 14 Days)',
                'policy_action_text' => '100% Full downpayment refund eligible per camp policy.',
            ],
            'status' => 'pending',
            'notes' => 'Customer requested cancellation with 18 days advance notice. Full refund recommended by Policy Engine.',
        ]);

        BookingStatusLog::create([
            'booking_id' => $b6->id,
            'old_status' => 'confirmed',
            'new_status' => 'cancellation_requested',
            'changed_by' => null,
            'note' => 'Guest requested cancellation per medical grounds. Queued in Payments & Refunds queue.',
            'created_at' => now()->subHours(3),
        ]);

        // -------------------------------------------------------------------------
        // BOOKING 7: Discovery Class (2 Pax) - Lead: Angelo Fernandez -> Cancelled by Guest (< 7 Days, Forfeited)
        // Pickup: Own Transportation
        // -------------------------------------------------------------------------
        $b7Start = Carbon::now()->addDays(4)->startOfDay();
        $b7 = Booking::create([
            'batch_id' => null,
            'booking_number' => 'CFP-2026-1007',
            'pin' => '1007',
            'class_type' => 'discovery',
            'is_certified_diver' => false,
            'start_date' => $b7Start,
            'end_date' => $b7Start->copy()->addDay(),
            'pickup_option' => 'own',
            'pickup_location' => null,
            'carpool_fee' => 0.00,
            'boat_dive' => false,
            'boat_dive_fee' => 0.00,
            'lgu_fee' => 600.00,
            'environmental_fee' => 100.00,
            'subtotal' => 8500.00,
            'total_amount' => 9200.00,
            'downpayment_amount' => 4000.00,
            'balance_amount' => 5200.00,
            'contact_name' => 'Angelo Fernandez',
            'contact_email' => 'angelo.fernandez@gmail.com',
            'contact_phone' => '0918 999 0011',
            'status' => 'cancelled_by_guest',
        ]);

        BookingParticipant::create([
            'booking_id' => $b7->id,
            'name' => 'Angelo Fernandez',
            'age' => 29,
            'health_condition' => 'Fit for diving.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);
        BookingParticipant::create([
            'booking_id' => $b7->id,
            'name' => 'Christine Villamayor',
            'age' => 28,
            'health_condition' => 'Fit for diving.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);

        $p7 = Payment::create([
            'booking_id' => $b7->id,
            'payment_method' => 'gcash',
            'transaction_id' => 'PAYM-20260831-GCASH-8812',
            'paymongo_payment_id' => 'pay_live_1kM9vP3xQ7wL',
            'amount' => 4000.00,
            'fee_amount' => 100.00,
            'net_amount' => 3900.00,
            'payment_type' => 'downpayment',
            'status' => 'forfeited',
            'paid_at' => now()->subDays(8),
        ]);

        CancellationRequest::create([
            'booking_id' => $b7->id,
            'calculated_refund_amount' => 0.00,
            'reason' => 'Personal schedule conflict.',
            'force_majeure_flag' => false,
            'status' => 'approved',
            'admin_notes' => 'Approved cancellation. Downpayment forfeited per 14-day policy window (< 7 days before departure).',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDay(),
        ]);

        RefundRequest::create([
            'payment_id' => $p7->id,
            'booking_id' => $b7->id,
            'requested_by' => 'customer',
            'requested_at' => now()->subDays(2),
            'eligibility_calculated' => [
                'days_until_dive' => 4,
                'eligible_for_refund' => false,
                'refund_percentage' => 0,
                'window_label' => 'Lockdown Window (< 7 Days)',
                'policy_action_text' => 'Non-refundable lockdown window. Forfeited per camp cancellation policy.',
            ],
            'status' => 'forfeited',
            'notes' => 'Downpayment forfeited per camp policy.',
        ]);

        BookingStatusLog::create([
            'booking_id' => $b7->id,
            'old_status' => 'cancellation_requested',
            'new_status' => 'cancelled_by_guest',
            'changed_by' => $admin->id,
            'note' => 'Cancellation approved. Downpayment forfeited per standard 14-day policy notice.',
            'created_at' => now()->subDay(),
        ]);

        // -------------------------------------------------------------------------
        // BOOKING 8: Discovery Class (2 Pax) - Lead: Christian Santiago -> Attached to Completed Batch 4
        // Pickup: Shell Tiendesitas - 3:00 AM
        // -------------------------------------------------------------------------
        $b8 = Booking::create([
            'batch_id' => $batch4->id,
            'booking_number' => 'CFP-2026-1008',
            'pin' => '1008',
            'class_type' => 'discovery',
            'is_certified_diver' => false,
            'start_date' => $batch4Start,
            'end_date' => $batch4End,
            'pickup_option' => 'carpool',
            'pickup_location' => self::PICKUP_TIENDESITAS,
            'carpool_fee' => 2000.00,
            'boat_dive' => false,
            'boat_dive_fee' => 0.00,
            'lgu_fee' => 600.00,
            'environmental_fee' => 100.00,
            'subtotal' => 8500.00,
            'total_amount' => 11200.00,
            'downpayment_amount' => 11200.00,
            'balance_amount' => 0.00,
            'contact_name' => 'Christian Santiago',
            'contact_email' => 'christian.santiago@gmail.com',
            'contact_phone' => '0917 888 9900',
            'status' => 'confirmed',
        ]);

        $p8_1 = BookingParticipant::create([
            'booking_id' => $b8->id,
            'name' => 'Christian Santiago',
            'age' => 30,
            'health_condition' => 'Fit for diving.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);
        $p8_2 = BookingParticipant::create([
            'booking_id' => $b8->id,
            'name' => 'Princess Dela Rosa',
            'age' => 25,
            'health_condition' => 'Fit for diving.',
            'swimmer_status' => 'swimmer',
            'price_per_person' => 4250.00,
        ]);

        foreach ([$p8_1, $p8_2] as $p) {
            ParticipantAssignment::create([
                'participant_id' => $p->id,
                'booking_id' => $b8->id,
                'coach_id' => $coachJose->id,
                'batch_id' => $batch4->id,
                'dive_date' => $batch4Start,
                'assigned_by' => $admin->id,
                'assigned_at' => $batch4Start->copy()->subDays(3),
                'status' => 'assigned',
                'is_ratio_override' => false,
            ]);
        }

        Payment::create([
            'booking_id' => $b8->id,
            'payment_method' => 'gcash',
            'transaction_id' => 'PAYM-20260831-GCASH-5501',
            'paymongo_payment_id' => 'pay_live_9vN4kP1xQ8wM',
            'amount' => 11200.00,
            'fee_amount' => 280.00,
            'net_amount' => 10920.00,
            'payment_type' => 'full_payment',
            'status' => 'completed',
            'paid_at' => $batch4Start->copy()->subDays(4),
        ]);

        // =========================================================================
        // 5. SEED COACH BROADCAST OPENING & APPLICATIONS FOR BATCH 6
        // =========================================================================

        $opening = CoachOpening::create([
            'batch_id' => $batch6->id,
            'dive_date' => $batch6Start,
            'needed_students_count' => 4,
            'status' => 'open',
            'posted_by' => $admin->id,
            'notes' => 'Open coaching slot for Discovery group (4 pax) in Mabini, Batangas.',
        ]);

        CoachRequest::create([
            'opening_id' => $opening->id,
            'batch_id' => $batch6->id,
            'coach_id' => $coachMark->id,
            'status' => 'pending',
            'notes' => 'Available to lead the Discovery group. Bringing safety float and dive line.',
            'created_at' => now()->subHours(2),
        ]);

        CoachRequest::create([
            'opening_id' => $opening->id,
            'batch_id' => $batch6->id,
            'coach_id' => $coachChristine->id,
            'status' => 'pending',
            'notes' => 'Available for weekend departure from Manila.',
            'created_at' => now()->subHour(),
        ]);

        // =========================================================================
        // 6. SEED REALISTIC DYNAMIC PRICING RULES
        // =========================================================================

        $pricingRules = [
            [
                'name' => 'Amihan Peak Season Rate',
                'description' => 'Seasonal dry-season markup during optimal diving months in Batangas (November to May).',
                'rule_type' => 'seasonality',
                'condition_operator' => null,
                'condition_value' => 'peak',
                'applies_to' => 'all',
                'adjustment_type' => 'increase',
                'adjustment_method' => 'percentage',
                'adjustment_value' => 10.00,
                'priority' => 1,
                'status' => 'active',
                'created_by' => $owner->id,
            ],
            [
                'name' => 'Habagat Off-Peak Monsoon Incentive',
                'description' => 'Special promotion to stimulate camp bookings and occupancy during off-peak rainy season (June to September).',
                'rule_type' => 'seasonality',
                'condition_operator' => null,
                'condition_value' => 'off_peak',
                'applies_to' => 'all',
                'adjustment_type' => 'decrease',
                'adjustment_method' => 'percentage',
                'adjustment_value' => 15.00,
                'priority' => 2,
                'status' => 'active',
                'created_by' => $owner->id,
            ],
            [
                'name' => 'High Occupancy Demand Surge',
                'description' => 'Automatically triggers when batch booking capacity exceeds 65% (30+ divers booked).',
                'rule_type' => 'demand',
                'condition_operator' => null,
                'condition_value' => 'high',
                'applies_to' => 'all',
                'adjustment_type' => 'increase',
                'adjustment_method' => 'percentage',
                'adjustment_value' => 10.00,
                'priority' => 3,
                'status' => 'active',
                'created_by' => $owner->id,
            ],
            [
                'name' => 'Early Bird Diver Reward',
                'description' => 'Fixed discount incentive for divers booking their 2D1N slot at least 21 days in advance.',
                'rule_type' => 'lead_time',
                'condition_operator' => '>=',
                'condition_value' => '21',
                'applies_to' => 'all',
                'adjustment_type' => 'decrease',
                'adjustment_method' => 'fixed',
                'adjustment_value' => 500.00,
                'priority' => 4,
                'status' => 'active',
                'created_by' => $admin->id,
            ],
            [
                'name' => 'Last-Minute Expedited Booking Surcharge',
                'description' => 'Surcharge for reservations made within 3 days of departure to cover rushed logistics & transport reservations.',
                'rule_type' => 'lead_time',
                'condition_operator' => '<=',
                'condition_value' => '3',
                'applies_to' => 'all',
                'adjustment_type' => 'increase',
                'adjustment_method' => 'fixed',
                'adjustment_value' => 350.00,
                'priority' => 5,
                'status' => 'active',
                'created_by' => $admin->id,
            ],
        ];

        foreach ($pricingRules as $r) {
            PricingRule::create($r);
        }

        // =========================================================================
        // 8. SEED SYSTEM SETTINGS
        // =========================================================================
        $this->call(SystemSettingsSeeder::class);
    }
}
