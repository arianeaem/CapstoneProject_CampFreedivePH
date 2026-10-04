<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingPriceAdjustment;
use App\Models\CancellationRequest;
use App\Models\CoachAvailability;
use App\Models\Payment;
use App\Models\PricingRule;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Models\User;
use App\Services\PricingRuleEngine;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Creates disposable, relative-date data for the October 6 demo.
 *
 * This seeder intentionally never truncates system_settings. Run it after
 * migrations and before the demo commands.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->clearDemoTables();

        $staff = $this->createStaff();
        $this->call(SystemSettingsSeeder::class);
        $rules = $this->createPricingRules($staff['admin']->id);
        $batches = $this->createBatches();
        $this->createCoachAvailability($staff['coaches'], $batches);

        $bookings = $this->createBookings($batches, $rules);
        $this->createRequests($bookings, $staff['admin']->id);
    }

    private function clearDemoTables(): void
    {
        $tables = [
            'refund_requests',
            'cancellation_requests',
            'reschedule_requests',
            'payment_status_logs',
            'payments',
            'booking_price_adjustments',
            'booking_status_logs',
            'booking_participants',
            'bookings',
            'participant_assignments',
            'assignment_logs',
            'assignment_release_requests',
            'coach_requests',
            'coach_openings',
            'coach_availabilities',
            'batch_risk_assessments',
            'hourly_assessments',
            'manual_overrides',
            'notification_logs',
            'batch_status_logs',
            'batches',
            'pricing_rules',
            'audit_logs',
            'users',
        ];

        Schema::disableForeignKeyConstraints();
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    /**
     * @return array{owner: User, admin: User, coaches: array<int, User>}
     */
    private function createStaff(): array
    {
        $password = Hash::make('DemoPass123!');
        $makeUser = function (string $name, string $email, string $role) use ($password): User {
            return User::create([
                'name' => $name,
                'email' => $email,
                'phone' => '09' . fake()->numerify('#########'),
                'password' => $password,
                'role' => $role,
                'status' => 'active',
                'must_change_password' => false,
                'email_verified_at' => now(),
            ]);
        };

        return [
            'owner' => $makeUser('Demo Owner', 'owner@example.com', 'owner'),
            'admin' => $makeUser('Demo Admin', 'admin@example.com', 'admin'),
            'coaches' => [
                $makeUser('Demo Coach Alpha', 'coach.alpha@example.com', 'coach'),
                $makeUser('Demo Coach Bravo', 'coach.bravo@example.com', 'coach'),
                $makeUser('Demo Coach Charlie', 'coach.charlie@example.com', 'coach'),
            ],
        ];
    }

    /**
     * @return array<int, PricingRule>
     */
    private function createPricingRules(int $adminId): array
    {
        $definitions = [
            ['High Demand Premium', 'demand', '==', 'high', 'increase', 10],
            ['Low Demand Discount', 'demand', '==', 'low', 'decrease', 10],
            ['Peak Season Premium', 'seasonality', '==', 'peak', 'increase', 10],
            ['Off-Peak Discount', 'seasonality', '==', 'off_peak', 'decrease', 10],
            ['Early Bird', 'lead_time', '>=', '30', 'decrease', 5],
            ['Last-Minute Fill', 'lead_time', '<=', '7', 'decrease', 10],
            ['Shoulder Season Offer', 'seasonality', '==', 'shoulder', 'decrease', 5],
        ];

        return collect($definitions)->map(function (array $definition, int $index) use ($adminId): PricingRule {
            [$name, $type, $operator, $value, $adjustmentType, $amount] = $definition;

            return PricingRule::create([
                'name' => $name,
                'description' => 'Demo pricing rule for October 6 presentation.',
                'rule_type' => $type,
                'condition_operator' => $operator,
                'condition_value' => $value,
                'applies_to' => 'all',
                'adjustment_type' => $adjustmentType,
                'adjustment_method' => 'percentage',
                'adjustment_value' => $amount,
                'priority' => $index + 1,
                'status' => 'active',
                'created_by' => $adminId,
            ]);
        })->all();
    }

    /**
     * @return array{history: array<int, Batch>, upcoming: array<int, Batch>}
     */
    private function createBatches(): array
    {
        $now = Carbon::now()->startOfDay();
        $history = collect([-60, -45, -30, -15])->map(
            fn (int $days, int $index) => $this->createBatch($now->copy()->addDays($days), 'completed', $index + 1)
        )->all();

        $upcoming = collect([7, 14, 21, 28, 35, 42, 49, 56])->map(
            fn (int $days, int $index) => $this->createBatch($now->copy()->addDays($days), $index === 1 ? 'full' : 'open', $index + 5)
        )->all();

        return ['history' => $history, 'upcoming' => $upcoming];
    }

    private function createBatch(Carbon $startDate, string $status, int $number): Batch
    {
        return Batch::create([
            'name' => 'Demo Batch ' . $number,
            'batch_code' => 'DEMO-' . $startDate->format('Ymd') . '-' . str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDay(),
            'lifecycle_status' => $status === 'completed' ? 'completed' : ($status === 'full' ? 'sold_out' : 'open'),
            'max_capacity' => 45,
            'status' => $status,
            'notes' => 'Simulated demo batch; not real customer data.',
        ]);
    }

    /**
     * @param array<int, User> $coaches
     * @param array{history: array<int, Batch>, upcoming: array<int, Batch>} $batches
     */
    private function createCoachAvailability(array $coaches, array $batches): void
    {
        foreach ($batches['upcoming'] as $batch) {
            foreach ($coaches as $coach) {
                CoachAvailability::create([
                    'coach_id' => $coach->id,
                    'date' => $batch->start_date,
                    'status' => 'available',
                    'notes' => 'Demo availability for coach matching.',
                ]);
                CoachAvailability::create([
                    'coach_id' => $coach->id,
                    'date' => $batch->end_date,
                    'status' => 'available',
                    'notes' => 'Demo availability for coach matching.',
                ]);
            }
        }
    }

    /**
     * @param array{history: array<int, Batch>, upcoming: array<int, Batch>} $batches
     * @param array<int, PricingRule> $rules
     * @return array<int, Booking>
     */
    private function createBookings(array $batches, array $rules): array
    {
        $allBatches = array_merge($batches['history'], $batches['upcoming']);
        $bookings = [];
        $sequence = 1;

        foreach ($allBatches as $batchIndex => $batch) {
            $bookingCount = $batchIndex < 4 ? 3 : 2;
            for ($bookingIndex = 0; $bookingIndex < $bookingCount; $bookingIndex++) {
                $pax = ($bookingIndex % 3) + 1;
                $classType = ['discovery', 'fundive', 'refinement'][$bookingIndex % 3];
                $booking = $this->createBooking($batch, $sequence++, $pax, $classType, $rules);
                $bookings[] = $booking;
            }
        }

        return $bookings;
    }

    /**
     * @param array<int, PricingRule> $rules
     */
    private function createBooking(Batch $batch, int $sequence, int $pax, string $classType, array $rules): Booking
    {
        $isCertified = $classType === 'fundive';
        $quote = app(PricingRuleEngine::class)->evaluate($classType, $batch->start_date, $isCertified, $pax);
        $ownTransport = $sequence % 2 === 0;
        $carpoolFee = $ownTransport ? 0 : 1200 * $pax;
        $boatFee = $sequence % 4 === 0 ? 600 * $pax : 0;
        $lguFee = 300 * $pax;
        $environmentalFee = 50 * $pax;
        $subtotal = round((float) $quote['adjusted_price_per_pax'] * $pax, 2);
        $total = $subtotal + $carpoolFee + $boatFee + $lguFee + $environmentalFee;
        $downpayment = min(($ownTransport ? 2000 : 3000) * $pax, $total);
        $isHistory = $batch->status === 'completed';
        $status = $isHistory ? 'completed' : 'confirmed';

        $booking = Booking::create([
            'batch_id' => $batch->id,
            'booking_number' => 'DEMO-' . now()->format('Y') . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'pin' => (string) (1000 + $sequence),
            'class_type' => $classType,
            'is_certified_diver' => $isCertified,
            'start_date' => $batch->start_date,
            'end_date' => $batch->end_date,
            'pickup_option' => $ownTransport ? 'own' : 'carpool',
            'pickup_location' => $ownTransport ? null : 'Demo Pickup Hub - 3:00 AM',
            'carpool_fee' => $carpoolFee,
            'boat_dive' => $boatFee > 0,
            'boat_dive_fee' => $boatFee,
            'lgu_fee' => $lguFee,
            'environmental_fee' => $environmentalFee,
            'subtotal' => $subtotal,
            'total_amount' => $total,
            'downpayment_amount' => $downpayment,
            'balance_amount' => $total - $downpayment,
            'contact_name' => 'Demo Customer ' . $sequence,
            'contact_email' => 'customer' . $sequence . '@example.com',
            'contact_phone' => '09' . str_pad((string) (170000000 + $sequence), 9, '0', STR_PAD_LEFT),
            'status' => $status,
        ]);

        for ($participantIndex = 1; $participantIndex <= $pax; $participantIndex++) {
            $participant = [
                'booking_id' => $booking->id,
                'name' => "Demo Student {$sequence}-{$participantIndex}",
                'age' => 20 + $participantIndex,
                'health_condition' => 'None declared',
                'swimmer_status' => 'swimmer',
                'price_per_person' => $quote['adjusted_price_per_pax'],
            ];
            if (Schema::hasColumn('booking_participants', 'birthdate')) {
                $participant['birthdate'] = Carbon::now()->subYears(20 + $participantIndex)->toDateString();
            }
            if (Schema::hasColumn('booking_participants', 'gender')) {
                $participant['gender'] = ['male', 'female', 'prefer_not_to_say'][$participantIndex % 3];
            }
            BookingParticipant::create($participant);
        }

        Payment::create([
                'booking_id' => $booking->id,
                'payment_method' => $sequence % 2 === 0 ? 'bank_transfer' : 'gcash',
                'transaction_id' => 'DEMO-TXN-' . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
                'amount' => $isHistory ? $total : $downpayment,
                'fee_amount' => 0,
                'net_amount' => $isHistory ? $total : $downpayment,
                'payment_type' => $isHistory ? 'full' : 'downpayment',
                'status' => 'completed',
                'paid_at' => now(),
        ]);

        foreach ($quote['adjustments'] as $adjustment) {
            $rule = collect($rules)->firstWhere('id', $adjustment['rule_id']);
            BookingPriceAdjustment::create([
                'booking_id' => $booking->id,
                'pricing_rule_id' => $rule?->id,
                'rule_name' => $adjustment['rule_name'],
                'rule_type' => $rule?->rule_type ?? 'demo',
                'condition_summary' => $rule?->condition_summary ?? 'Demo adjustment',
                'base_price' => $quote['base_price_per_pax'],
                'adjustment_amount' => $adjustment['delta_per_pax'],
                'adjusted_price' => $quote['base_price_per_pax'] + $adjustment['delta_per_pax'],
            ]);
        }

        return $booking;
    }

    /**
     * @param array<int, Booking> $bookings
     */
    private function createRequests(array $bookings, int $adminId): void
    {
        $upcoming = array_values(array_filter($bookings, fn (Booking $booking) => $booking->start_date->isFuture()));
        $rescheduleBooking = $upcoming[0];
        $rescheduleBooking->update(['status' => 'reschedule_requested']);
        RescheduleRequest::create([
            'booking_id' => $rescheduleBooking->id,
            'current_start_date' => $rescheduleBooking->start_date,
            'current_end_date' => $rescheduleBooking->end_date,
            'requested_start_date' => $rescheduleBooking->start_date->copy()->addDays(7),
            'requested_end_date' => $rescheduleBooking->end_date->copy()->addDays(7),
            'reason' => 'Demo customer schedule conflict.',
            'status' => 'pending',
        ]);

        $cancellationBooking = $upcoming[1];
        $cancellationBooking->update(['status' => 'cancellation_requested']);
        CancellationRequest::create([
            'booking_id' => $cancellationBooking->id,
            'calculated_refund_amount' => $cancellationBooking->downpayment_amount,
            'reason' => 'Demo customer cancellation request.',
            'status' => 'pending',
        ]);

        $refundBooking = $upcoming[2];
        $payment = $refundBooking->payments()->firstOrCreate(
            ['transaction_id' => 'DEMO-TXN-REFUND-001'],
            [
                'payment_method' => 'gcash',
                'amount' => $refundBooking->downpayment_amount,
                'fee_amount' => 0,
                'net_amount' => $refundBooking->downpayment_amount,
                'payment_type' => 'downpayment',
                'status' => 'completed',
                'paid_at' => now(),
            ]
        );
        $refundBooking->update(['status' => 'cancelled_by_guest']);
        RefundRequest::create([
            'payment_id' => $payment->id,
            'booking_id' => $refundBooking->id,
            'requested_by' => 'customer',
            'eligibility_calculated' => json_encode([
                'policy_tier' => 'more_than_two_weeks',
                'refund_percentage' => 100,
                'calculated_refund' => $refundBooking->downpayment_amount,
            ]),
            'status' => 'pending',
            'notes' => 'Demo refund request linked to a cancelled booking.',
        ]);
    }
}
