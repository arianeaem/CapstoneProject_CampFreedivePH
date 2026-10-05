<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $owner = User::where('role', 'owner')->first();
        $ownerId = $owner?->id;

        $settings = [
            // 1. Camp Operations
            [
                'group' => 'camp_operations',
                'key' => 'camp_operations.max_batch_capacity',
                'value' => '45',
                'type' => 'integer',
                'description' => 'Maximum number of participants allowed per camp batch.',
            ],
            [
                'group' => 'camp_operations',
                'key' => 'camp_operations.coach_student_ratio',
                'value' => '4',
                'type' => 'integer',
                'description' => 'Standard ratio of students assigned per coach (1 coach per N students).',
            ],
            [
                'group' => 'camp_operations',
                'key' => 'camp_operations.min_coaches_per_batch',
                'value' => '2',
                'type' => 'integer',
                'description' => 'Minimum number of coaches required for a batch to operate.',
            ],

            // 2. Program Pricing & Inclusions/Exclusions
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.base_price_discovery',
                'value' => '4250.00',
                'type' => 'float',
                'description' => 'Base price for Discovery Freedive program per participant.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.base_price_fundive_cert',
                'value' => '2500.00',
                'type' => 'float',
                'description' => 'Base price for Fun Dive (Certified) program per participant.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.base_price_fundive_noncert',
                'value' => '3300.00',
                'type' => 'float',
                'description' => 'Base price for Fun Dive (Non-Certified) program per participant.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.base_price_refinement',
                'value' => '4100.00',
                'type' => 'float',
                'description' => 'Base price for Skills Refinement program per participant.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.dynamic_pricing_cap_percent',
                'value' => '30.00',
                'type' => 'float',
                'description' => 'Maximum allowable price surge or discount percentage.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.discovery_inclusions',
                'value' => json_encode([
                    '2 open water dives (2-3 hrs per session)',
                    '1 pool session (10 ft deep pool access)',
                    '2D1N shared AC room accommodation',
                    'Lesson fee and coach fee',
                    'Safety buoy set up',
                    '3 full board meals',
                    'Photos and videos',
                    'Gears',
                ]),
                'type' => 'json',
                'description' => 'List of included items for Discovery Class.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.discovery_exclusions',
                'value' => json_encode([
                    'Transportation (We arrange carpool)',
                    'Boat dive (optional)',
                    'Mabini LGU divepass',
                ]),
                'type' => 'json',
                'description' => 'List of excluded items for Discovery Class.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.fundive_inclusions',
                'value' => json_encode([
                    '2 open water dives (2-3 hrs per session)',
                    '1 pool session (10 ft deep pool access)',
                    '2D1N shared AC room accommodation',
                    'Safety coach fee (for non-certified)',
                    'Safety buoy set up',
                    '3 full board meals',
                    'Photos and videos',
                    'Gears',
                ]),
                'type' => 'json',
                'description' => 'List of included items for Fun Dive.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.fundive_exclusions',
                'value' => json_encode([
                    'Transportation (We arrange carpool)',
                    'Boat dive (optional)',
                    'Mabini LGU divepass',
                ]),
                'type' => 'json',
                'description' => 'List of excluded items for Fun Dive.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.refinement_inclusions',
                'value' => json_encode([
                    '2 open water dives (2-3 hrs per session)',
                    '1 pool session (10 ft deep pool access)',
                    '2D1N shared AC room accommodation',
                    '3 full board meals',
                    'Safety buoy set up',
                    'Photos and videos',
                    'Coach fee',
                    'Gears',
                ]),
                'type' => 'json',
                'description' => 'List of included items for Skills Refinement.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.refinement_exclusions',
                'value' => json_encode([
                    'Transportation (We arrange carpool)',
                    'Boat dive (optional)',
                    'Mabini LGU divepass',
                ]),
                'type' => 'json',
                'description' => 'List of excluded items for Skills Refinement.',
            ],

            // 3. Downpayment Deposits
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.downpayment_carpool',
                'value' => '3000.00',
                'type' => 'float',
                'description' => 'Fixed reservation downpayment deposit per person when Carpool is selected.',
            ],
            [
                'group' => 'program_pricing',
                'key' => 'program_pricing.downpayment_own_transpo',
                'value' => '2000.00',
                'type' => 'float',
                'description' => 'Fixed reservation downpayment deposit per person when Own Transportation is selected.',
            ],

            // 4. Add-ons & Transportation
            [
                'group' => 'addons',
                'key' => 'addons.carpool_fee_per_head',
                'value' => '1200.00',
                'type' => 'float',
                'description' => 'Roundtrip carpool transportation fee per person from Manila to Mabini.',
            ],
            [
                'group' => 'addons',
                'key' => 'addons.boat_dive_fee_per_head',
                'value' => '600.00',
                'type' => 'float',
                'description' => 'Optional private banca boat dive fee per person.',
            ],
            [
                'group' => 'addons',
                'key' => 'addons.lgu_tourism_pass_fee',
                'value' => '300.00',
                'type' => 'float',
                'description' => 'Mabini LGU Tourism Dive Pass fee per participant.',
            ],
            [
                'group' => 'addons',
                'key' => 'addons.environmental_fee',
                'value' => '50.00',
                'type' => 'float',
                'description' => 'Mabini Marine Sanctuary Ecological fee per participant.',
            ],
            [
                'group' => 'addons',
                'key' => 'addons.pickup_locations',
                'value' => json_encode([
                    [
                        'id' => 'monumento',
                        'name' => 'Monumento Hypermarket - 2:30 AM',
                        'time' => '2:30 AM',
                        'address' => 'Monumento Hypermarket, Caloocan City',
                    ],
                    [
                        'id' => 'tiendesitas',
                        'name' => 'Shell Tiendesitas - 3:00 AM',
                        'time' => '3:00 AM',
                        'address' => 'Shell C5 Tiendesitas, Pasig City',
                    ],
                    [
                        'id' => 'market_market',
                        'name' => 'Market Market Taxi Bay - 3:40 AM',
                        'time' => '3:40 AM',
                        'address' => 'Market! Market! Taxi Bay, BGC, Taguig City',
                    ],
                    [
                        'id' => 'alabang',
                        'name' => 'Alabang Starmall - 4:15 AM',
                        'time' => '4:15 AM',
                        'address' => 'Starmall Alabang Southbound, Muntinlupa City',
                    ],
                    [
                        'id' => 'sto_tomas',
                        'name' => 'Sto Tomas Exit - 5:30 AM',
                        'time' => '5:30 AM',
                        'address' => 'Sto Tomas SLEX Toll Exit, Batangas',
                    ],
                ]),
                'type' => 'json',
                'description' => 'List of configurable carpool pickup hubs and departure times.',
            ],

            // 5. Booking & Cancellation Rules
            [
                'group' => 'booking_cancellation',
                'key' => 'booking_cancellation.full_refund_threshold_days',
                'value' => '14',
                'type' => 'integer',
                'description' => 'Days before batch start when a customer is eligible for a full refund on cancellation.',
            ],
            [
                'group' => 'booking_cancellation',
                'key' => 'booking_cancellation.reschedule_only_threshold_days',
                'value' => '7',
                'type' => 'integer',
                'description' => 'Days before batch start within which only rescheduling is permitted (no refund).',
            ],

            // 6. Account & Security
            [
                'group' => 'account_security',
                'key' => 'account_security.session_timeout_minutes',
                'value' => '120',
                'type' => 'integer',
                'description' => 'Minutes of user inactivity before backoffice session expires.',
            ],
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'group' => $setting['group'],
                    'value' => $setting['value'],
                    'type' => $setting['type'],
                    'description' => $setting['description'],
                    'updated_by' => $ownerId,
                ]
            );
        }

        app(\App\Services\SystemSettingService::class)->clearCache();
    }
}
