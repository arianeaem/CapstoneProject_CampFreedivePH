<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingStatusLog;
use App\Models\Payment;
use App\Models\PaymentStatusLog;
use App\Services\AuditLogger;
use App\Services\PricingRuleEngine;
use App\Services\SlotReservationService;
use App\Services\WeatherSafetyService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Public Customer Booking & Reservation Controller.
 *
 * Business Workflow & Safety Policies:
 * - Directs users through the multi-step booking process (`booking.create`).
 * - Real-time Weather Feeds: Integrates live Open-Meteo forecasts and ML safety clearance.
 * - Capacity Bounds: Enforces a strict 45-pax cap per weekend trip across all batches
 *   to ensure adherence to Coast Guard banca vessel limits and a 1:4 instructor-to-student safety ratio.
 * - Distributed Slot Locking: Manages temporary 15-minute slot reservations in cache during checkout step 4
 *   to eliminate race-condition overbooking under concurrent traffic.
 * - Payment Guarantees: Reserves slots in `pending_downpayment` status and releases them if
 *   unpaid within the slot-hold grace period.
 */
class BookingController extends Controller
{
    public function __construct(
        protected WeatherSafetyService $weatherSafetyService,
        protected PricingRuleEngine $pricingRuleEngine,
        protected SlotReservationService $slotReservationService
    ) {}

    /**
     * Display the customer-facing booking form.
     *
     * @param Request $request Query parameters (e.g. `class` pre-selection).
     * @return View Renders the step-by-step booking interface.
     */
    public function create(Request $request): View
    {
        $selectedClass = strtolower($request->query('class', 'discovery'));
        if (!in_array($selectedClass, ['discovery', 'fundive', 'refinement'])) {
            $selectedClass = 'discovery';
        }

        $confirmedBookingData = null;
        $initialStep = 1;

        if ($request->has('confirmed')) {
            $bookingNumber = $request->query('confirmed');
            $pin = $request->query('pin');

            $bookingQuery = Booking::with(['participants', 'priceAdjustments', 'payments'])
                ->where('booking_number', $bookingNumber);

            if ($pin) {
                $bookingQuery->where('pin', $pin);
            }

            $confirmedBooking = $bookingQuery->first();
            if ($confirmedBooking) {
                $initialStep = 5;
                $downpaymentPaid = (float) $confirmedBooking->payments()
                    ->where('status', 'paid')
                    ->sum('amount');
                if ($downpaymentPaid <= 0) {
                    $downpaymentPaid = (float) $confirmedBooking->downpayment_amount;
                }

                $confirmedBookingData = [
                    'booking_number' => $confirmedBooking->booking_number,
                    'pin' => $confirmedBooking->pin,
                    'downpayment_paid' => $downpaymentPaid,
                    'balance_due' => (float) $confirmedBooking->balance_amount,
                    'manage_url' => route('manage.show', ['booking_number' => $confirmedBooking->booking_number, 'pin' => $confirmedBooking->pin]),
                    'class_type' => $confirmedBooking->class_type,
                    'start_date' => $confirmedBooking->start_date ? $confirmedBooking->start_date->format('Y-m-d') : '',
                    'end_date' => $confirmedBooking->end_date ? $confirmedBooking->end_date->format('Y-m-d') : '',
                    'contact_email' => $confirmedBooking->contact_email,
                    'contact_name' => $confirmedBooking->contact_name,
                    'pickup_option' => $confirmedBooking->pickup_option,
                    'pickup_location' => $confirmedBooking->pickup_location,
                    'participants' => $confirmedBooking->participants->map(fn($p) => [
                        'name' => $p->name,
                        'first_name' => explode(' ', $p->name)[0] ?? $p->name,
                        'last_name' => substr(strstr($p->name, ' '), 1) ?: '',
                        'age' => $p->age,
                        'health_condition' => $p->health_condition,
                        'swimmer_status' => $p->swimmer_status,
                    ])->toArray(),
                    'adjustments' => $confirmedBooking->priceAdjustments->map(fn($adj) => [
                        'rule_id' => $adj->pricing_rule_id,
                        'rule_name' => $adj->rule_name,
                        'formatted_adjustment' => ($adj->adjustment_amount >= 0 ? '+' : '−') . '₱' . number_format(abs($adj->adjustment_amount), 2),
                        'delta_per_pax' => (float) $adj->adjustment_amount,
                    ])->toArray(),
                ];
            }
        }

        $settingService = app(\App\Services\SystemSettingService::class);

        $discPrice = (float) ($settingService->get('program_pricing.base_price_discovery', 4250) ?? 4250);
        $funCertPrice = (float) ($settingService->get('program_pricing.base_price_fundive_cert', 2500) ?? 2500);
        $funNonCertPrice = (float) ($settingService->get('program_pricing.base_price_fundive_noncert', 3300) ?? 3300);
        $refPrice = (float) ($settingService->get('program_pricing.base_price_refinement', 4100) ?? 4100);

        $packagesData = [
            'discovery' => [
                'price' => $discPrice,
                'inclusions' => (array) $settingService->get('program_pricing.discovery_inclusions', [
                    '2 open water dives (2-3 hrs per session)',
                    '1 pool session (10 ft deep pool access)',
                    '2D1N shared AC room accommodation',
                    'Lesson fee and coach fee',
                    'Safety buoy set up',
                    '3 full board meals',
                    'Photos and videos',
                    'Gears (mask, snorkel, fins, weight belt)',
                ]),
                'exclusions' => (array) $settingService->get('program_pricing.discovery_exclusions', [
                    'Transportation (We arrange carpool)',
                    'Boat dive (optional sanctuary trip +₱600/pax)',
                    'Mabini LGU municipal environmental fee & dive pass',
                ]),
            ],
            'fundive' => [
                'price_certified' => $funCertPrice,
                'price_non_certified' => $funNonCertPrice,
                'inclusions' => (array) $settingService->get('program_pricing.fundive_inclusions', [
                    '2 open water dives (2-3 hrs per session)',
                    '1 pool session (10 ft deep pool access)',
                    '2D1N shared AC room accommodation',
                    'Safety coach fee (for non-certified option)',
                    'Safety buoy set up',
                    '3 full board meals',
                    'Photos and videos',
                    'Gears (mask, snorkel, fins, weight belt)',
                ]),
                'exclusions' => (array) $settingService->get('program_pricing.fundive_exclusions', [
                    'Transportation (We arrange carpool)',
                    'Boat dive (optional sanctuary trip +₱600/pax)',
                    'Mabini LGU municipal environmental fee & dive pass',
                ]),
            ],
            'refinement' => [
                'price' => $refPrice,
                'inclusions' => (array) $settingService->get('program_pricing.refinement_inclusions', [
                    '2 open water dives (2-3 hrs per session)',
                    '1 pool session (10 ft deep pool access)',
                    '2D1N shared AC room accommodation',
                    'Coach fee and depth coaching',
                    'Safety buoy set up',
                    '3 full board meals',
                    'Photos and videos',
                    'Gears (mask, snorkel, fins, weight belt)',
                ]),
                'exclusions' => (array) $settingService->get('program_pricing.refinement_exclusions', [
                    'Transportation (We arrange carpool)',
                    'Boat dive (optional sanctuary trip +₱600/pax)',
                    'Mabini LGU municipal environmental fee & dive pass',
                ]),
            ],
        ];

        $feesData = [
            'carpool' => (float) ($settingService->get('addons.carpool_fee_per_head', 1200.00) ?? 1200.00),
            'boat_dive' => (float) ($settingService->get('addons.boat_dive_fee_per_head', 600.00) ?? 600.00),
            'lgu_pass' => (float) ($settingService->get('addons.lgu_tourism_pass_fee', 300.00) ?? 300.00),
            'environmental' => (float) ($settingService->get('addons.environmental_fee', 50.00) ?? 50.00),
        ];

        $downpaymentsData = [
            'carpool' => (float) ($settingService->get('program_pricing.downpayment_carpool', 3000.00) ?? 3000.00),
            'own_transpo' => (float) ($settingService->get('program_pricing.downpayment_own_transpo', 2000.00) ?? 2000.00),
        ];

        $pickupPoints = $settingService->get('addons.pickup_locations', [
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
                'address' => 'Sto. Tomas SLEX / STAR Tollway Exit, Batangas',
            ],
        ]);

        return view('booking.create', compact(
            'selectedClass',
            'pickupPoints',
            'confirmedBookingData',
            'initialStep',
            'packagesData',
            'feesData',
            'downpaymentsData'
        ));
    }

    /**
     * Retrieves weather and marine risk forecast for selected session dates.
     *
     * @param Request $request Contains `start_date` and `end_date` (YYYY-MM-DD).
     * @return JsonResponse Returns standardized 5-tier safety classification and physical readings.
     */
    public function checkWeather(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $forecast = $this->weatherSafetyService->getForecast(
            $validated['start_date'],
            $validated['end_date']
        );

        return response()->json($forecast);
    }

    /**
     * Computes live dynamic pricing quote based on booking parameters.
     *
     * Business Logic:
     * Applies early-bird discounts, certified diver deductions, and group tiered pricing
     * via the PricingRuleEngine.
     *
     * @param Request $request Contains `class_type`, `start_date`, `is_certified_diver`, and `participants_count`.
     * @return JsonResponse Breakdown of base price, discounts, subtotal, and regulatory fees.
     */
    public function getPricingQuote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_type' => 'required|string|in:discovery,fundive,refinement',
            'start_date' => 'required|date',
            'is_certified_diver' => ['required_if:class_type,fundive', 'nullable', 'boolean'],
            'participants_count' => 'nullable|integer|min:1|max:10',
        ]);

        $quote = $this->pricingRuleEngine->evaluate(
            $validated['class_type'],
            $validated['start_date'],
            (bool) ($validated['is_certified_diver'] ?? false),
            (int) ($validated['participants_count'] ?? 1)
        );

        return response()->json($quote);
    }

    /**
     * Validates and creates a new booking reservation in atomic database transaction.
     *
     * Business Workflow:
     * 1. Validates participant medical disclosures and minimum emergency contact details.
     * 2. Enforces the 45-pax total weekend batch capacity limit.
     * 3. Re-evaluates final pricing and assigns municipal LGU & environmental fees.
     * 4. Allocates a unique booking code (e.g. CFP-2026-XXXXX) and 4-digit guest PIN.
     * 5. Initializes booking and downpayment records in `pending_downpayment` status.
     *
     * @param Request $request Complete booking payload.
     * @return JsonResponse Confirmation containing booking number, PIN, downpayment amount, and payment options.
     */
    public function store(Request $request): JsonResponse
    {
        $settingService = app(\App\Services\SystemSettingService::class);
        $pickupPoints = $settingService->get('addons.pickup_locations', [
            ['id' => 'monumento', 'name' => 'Monumento Hypermarket - 2:30 AM'],
            ['id' => 'tiendesitas', 'name' => 'Shell Tiendesitas - 3:00 AM'],
            ['id' => 'market_market', 'name' => 'Market Market Taxi Bay - 3:40 AM'],
            ['id' => 'alabang', 'name' => 'Alabang Starmall - 4:15 AM'],
            ['id' => 'sto_tomas', 'name' => 'Sto Tomas Exit - 5:30 AM'],
        ]);
        $validPickupLocations = collect($pickupPoints)
            ->map(fn($p) => [$p['id'] ?? null, $p['name'] ?? null])
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();

        $allowedSuffixes = ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V', 'None'];

        $validated = $request->validate([
            'class_type' => 'required|string|in:discovery,fundive,refinement',
            'is_certified_diver' => ['required_if:class_type,fundive', 'nullable', 'boolean'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) use ($request) {
                    $startDate = $request->input('start_date');
                    if (!$startDate) {
                        return;
                    }
                    try {
                        $start = Carbon::parse($startDate)->startOfDay();
                        $end = Carbon::parse($value)->startOfDay();
                        if ($start->copy()->addDay()->format('Y-m-d') !== $end->format('Y-m-d')) {
                            $fail('The end date must be exactly one calendar day after the start date.');
                        }
                    } catch (\Throwable $e) {
                        $fail('The end date is invalid.');
                    }
                },
            ],
            'participants' => 'required|array|min:1|max:10',
            'participants.*.name' => 'nullable|string|max:255',
            'participants.*.first_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'participants.*.middle_name' => ['nullable', 'string', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'participants.*.no_middle_name' => 'nullable|boolean',
            'participants.*.last_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'participants.*.suffix' => ['nullable', 'string', Rule::in($allowedSuffixes)],
            'participants.*.birthdate' => 'nullable|date|before_or_equal:today',
            'participants.*.gender' => 'nullable|in:male,female,non_binary,prefer_not_to_say',
            'participants.*.age' => 'required_without:participants.*.birthdate|nullable|integer|min:8|max:85',
            'participants.*.health_condition' => 'nullable|string|max:500',
            'participants.*.swimmer_status' => 'nullable|string|in:non_swimmer,beginner,intermediate,advanced,swimmer,casual_swimmer,confident_swimmer',
            'contact_name' => 'nullable|string|max:255',
            'contact_first_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'contact_middle_name' => ['nullable', 'string', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'contact_no_middle_name' => 'nullable|boolean',
            'contact_last_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'contact_suffix' => ['nullable', 'string', Rule::in($allowedSuffixes)],
            'contact_email' => 'required|email|max:255',
            'contact_phone' => ['required', 'string', 'regex:/^(\+?63|0)?[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}$/'],
            'contact_facebook' => 'nullable|string|max:255',
            'pickup_option' => 'required|string|in:carpool,own',
            'pickup_location' => [
                'required_if:pickup_option,carpool',
                'nullable',
                'string',
                Rule::in($validPickupLocations),
            ],
            'boat_dive' => 'nullable|boolean',
            'confirmation_ack' => 'required|accepted',
            'has_agreed_to_terms' => 'required|accepted',
            'payment_method' => ['nullable', 'string', 'in:paymongo'],
        ], [
            'pickup_location.required_if' => 'Please select a carpool pickup location.',
            'pickup_location.in' => 'Please select a valid configured pickup location.',
            'contact_phone.regex' => 'Please enter a valid Philippine mobile number (e.g. +63 917-123-4567 or 09171234567).',
            'participants.*.first_name.required' => 'Participant first name is required.',
            'participants.*.first_name.min' => 'Participant first name must be at least 2 characters.',
            'participants.*.first_name.regex' => 'Participant first name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'participants.*.middle_name.regex' => 'Participant middle name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'participants.*.last_name.required' => 'Participant last name is required.',
            'participants.*.last_name.min' => 'Participant last name must be at least 2 characters.',
            'participants.*.last_name.regex' => 'Participant last name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'contact_first_name.required' => 'Primary contact first name is required.',
            'contact_first_name.min' => 'Primary contact first name must be at least 2 characters.',
            'contact_first_name.regex' => 'Primary contact first name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'contact_middle_name.regex' => 'Primary contact middle name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'contact_last_name.required' => 'Primary contact last name is required.',
            'contact_last_name.min' => 'Primary contact last name must be at least 2 characters.',
            'contact_last_name.regex' => 'Primary contact last name may only contain letters (including Ñ/ñ), spaces, hyphens, and periods.',
            'confirmation_ack.accepted' => 'You must confirm that all details provided are accurate.',
            'has_agreed_to_terms.accepted' => 'You must agree to the Terms & Conditions and Privacy Policy to complete your booking.',
        ]);

        // Re-check the selected dates server-side. The browser preview is advisory
        // and must not be able to bypass a critical safety classification.
        $weatherForecast = $this->weatherSafetyService->getForecast(
            $validated['start_date'],
            $validated['end_date']
        );
        $criticalRisk = ($weatherForecast['risk_level'] ?? null) === 'critical_risk'
            || ($weatherForecast['overall_classification'] ?? null) === 'Critical Risk'
            || ($weatherForecast['day1']['classification'] ?? null) === 'Critical Risk'
            || ($weatherForecast['day2']['classification'] ?? null) === 'Critical Risk';

        if ($criticalRisk || ($weatherForecast['is_bookable'] ?? true) === false) {
            return response()->json([
                'success' => false,
                'message' => 'This date cannot be booked because the forecast is classified as Critical Risk. Please choose another date.',
                'weather' => $weatherForecast,
            ], 422);
        }

        $cfn = trim($validated['contact_first_name'] ?? '');
        $cmn = (!empty($validated['contact_no_middle_name'])) ? '' : trim($validated['contact_middle_name'] ?? '');
        $cln = trim($validated['contact_last_name'] ?? '');
        $csuf = trim($validated['contact_suffix'] ?? '');
        if ($csuf === 'None' || $csuf === 'none') {
            $csuf = '';
        }
        $assembledContact = implode(' ', array_filter([$cfn, $cmn, $cln, $csuf]));

        $contactName = !empty($validated['contact_name']) 
            ? $validated['contact_name'] 
            : $assembledContact;

        $paxCount = count($validated['participants']);
        $startDate = Carbon::parse($validated['start_date'])->format('Y-m-d');
        $endDate = Carbon::parse($validated['end_date'])->format('Y-m-d');
        $isCertified = !empty($validated['is_certified_diver']);

        try {
            return $this->slotReservationService->withLock($startDate, function () use (
                $validated,
                $contactName,
                $paxCount,
                $startDate,
                $endDate,
                $isCertified,
                $request
            ) {
                // Business Logic - Distributed Capacity Enforcement:
                // Capped at 45 pax per weekend trip to comply with Philippine Coast Guard banca passenger
                // limits and maintain an instructor-to-diver ratio of 1:4.
                // Accounts for both confirmed bookings in the database AND active 15-minute checkout holds.
                $availableSlots = $this->slotReservationService->getAvailableSlots($startDate);

                if ($paxCount > $availableSlots) {
                    return response()->json([
                        'success' => false,
                        'message' => "Capacity limit reached for {$startDate}. Only {$availableSlots} slot(s) remaining for this weekend trip. Please select another date or reduce group size.",
                    ], 422);
                }

                // Pricing Logic:
                // Evaluates dynamic pricing rules (early-bird discounts, certified diver deductions, group discounts).
                $quote = $this->pricingRuleEngine->evaluate(
                    $validated['class_type'],
                    $startDate,
                    $isCertified,
                    $paxCount
                );

                $classPrice = $quote['adjusted_price_per_pax'];
                $subtotal = $quote['subtotal'];

                // Regulatory & Logistics Fees:
                $settingService = app(\App\Services\SystemSettingService::class);
                $lguFeeRate = (float) ($settingService->get('addons.lgu_tourism_pass_fee', 300.00) ?? 300.00);
                $envFeeRate = (float) ($settingService->get('addons.environmental_fee', 50.00) ?? 50.00);
                $carpoolFeeRate = (float) ($settingService->get('addons.carpool_fee_per_head', 1200.00) ?? 1200.00);
                $boatDiveFeeRate = (float) ($settingService->get('addons.boat_dive_fee_per_head', 600.00) ?? 600.00);

                $lguFee = $lguFeeRate * $paxCount;
                $envFee = $envFeeRate * $paxCount;
                $carpoolFee = ($validated['pickup_option'] === 'carpool') ? ($carpoolFeeRate * $paxCount) : 0.00;
                $boatDiveFee = (!empty($validated['boat_dive']) && $validated['boat_dive']) ? ($boatDiveFeeRate * $paxCount) : 0.00;

                $totalAmount = $subtotal + $lguFee + $envFee + $carpoolFee + $boatDiveFee;
                
                // Deposit Policy (Carpool: ₱3,000/head, Own Transpo: ₱2,000/head by default):
                $dpCarpool = (float) ($settingService->get('program_pricing.downpayment_carpool', 3000.00) ?? 3000.00);
                $dpOwn = (float) ($settingService->get('program_pricing.downpayment_own_transpo', 2000.00) ?? 2000.00);

                $downpaymentPerHead = ($validated['pickup_option'] === 'carpool') ? $dpCarpool : $dpOwn;
                $downpaymentAmount = min($totalAmount, $downpaymentPerHead * $paxCount);
                $balanceAmount = max(0, $totalAmount - $downpaymentAmount);

                // Auto-assign batch roster for this weekend dates
                $batch = app(\App\Services\BatchManagementService::class)->findOrCreateBatchForDates($startDate, $endDate);

                // Security Credentials:
                // 4-digit PIN enables self-service booking portal lookup without requiring traditional password registration.
                $bookingNumber = 'CFP-' . date('Y') . '-' . strtoupper(Str::random(5));
                $pin = str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);

                // Atomic Transaction:
                // Guarantees booking, participant rows, dynamic pricing audits, and payment records
                // are committed in sync; rolls back completely if PayMongo session initialization fails.
                DB::beginTransaction();
                try {
                    $booking = Booking::create([
                        'booking_number' => $bookingNumber,
                        'pin' => $pin,
                        'batch_id' => $batch->id,
                        'class_type' => $validated['class_type'],
                        'is_certified_diver' => $isCertified,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'pickup_option' => $validated['pickup_option'],
                        'pickup_location' => $validated['pickup_location'] ?? null,
                        'carpool_fee' => $carpoolFee,
                        'boat_dive' => !empty($validated['boat_dive']) && $validated['boat_dive'],
                        'boat_dive_fee' => $boatDiveFee,
                        'lgu_fee' => $lguFee,
                        'environmental_fee' => $envFee,
                        'subtotal' => $subtotal,
                        'total_amount' => $totalAmount,
                        'downpayment_amount' => $downpaymentAmount,
                        'balance_amount' => $balanceAmount,
                        'contact_name' => $contactName ?: 'Guest',
                        'contact_email' => $validated['contact_email'],
                        'contact_phone' => $validated['contact_phone'],
                        'contact_facebook' => $validated['contact_facebook'] ?? null,
                        'status' => 'pending_downpayment',
                    ]);

                    // Save individual participant health questionnaires and medical disclosures
                    foreach ($validated['participants'] as $pData) {
                        $pfn = trim($pData['first_name'] ?? '');
                        $pmn = (!empty($pData['no_middle_name'])) ? '' : trim($pData['middle_name'] ?? '');
                        $pln = trim($pData['last_name'] ?? '');
                        $psuf = trim($pData['suffix'] ?? '');
                        if ($psuf === 'None' || $psuf === 'none') {
                            $psuf = '';
                        }
                        $assembledPName = implode(' ', array_filter([$pfn, $pmn, $pln, $psuf]));
                        $pName = !empty($pData['name']) ? $pData['name'] : $assembledPName;

                        $booking->participants()->create([
                            'name' => $pName ?: 'Participant',
                            'birthdate' => !empty($pData['birthdate']) ? $pData['birthdate'] : null,
                            'gender' => $pData['gender'] ?? null,
                            'age' => !empty($pData['birthdate'])
                                ? Carbon::parse($pData['birthdate'])->age
                                : (int) $pData['age'],
                            'health_condition' => $pData['health_condition'] ?? 'None',
                            'swimmer_status' => $pData['swimmer_status'] ?? 'beginner',
                            'price_per_person' => $classPrice,
                        ]);
                    }

                    // Pricing Audit Trail:
                    // Persists exact snapshot of active rule adjustments applied at checkout time
                    // to preserve pricing integrity against future admin rule modifications.
                    foreach ($quote['adjustments'] as $adj) {
                        $booking->priceAdjustments()->create([
                            'pricing_rule_id' => $adj['rule_id'],
                            'rule_name' => $adj['rule_name'],
                            'rule_type' => $adj['rule_type'],
                            'condition_summary' => $adj['condition_summary'],
                            'base_price' => $quote['base_price_per_pax'],
                            'adjustment_amount' => $adj['delta_per_pax'],
                            'adjusted_price' => $quote['adjusted_price_per_pax'],
                        ]);
                    }

                    // Payment Session Lifecycle:
                    // Expires in 24 hours to automatically purge uncompleted reservations.
                    $paymentMethod = $validated['payment_method'] ?? 'paymongo';
                    $transactionId = 'PAY-' . strtoupper(Str::random(10));

                    $payment = Payment::create([
                        'booking_id' => $booking->id,
                        'payment_method' => 'paymongo',
                        'transaction_id' => $transactionId,
                        'amount' => $downpaymentAmount,
                        'fee_amount' => 0.00,
                        'net_amount' => $downpaymentAmount,
                        'payment_type' => 'downpayment',
                        'status' => 'pending',
                        'expires_at' => now()->addHours(24),
                    ]);

                    // Register temporary 15-minute slot hold in cache during active checkout
                    $this->slotReservationService->acquireHold($startDate, $bookingNumber, $paxCount, SlotReservationService::DEFAULT_HOLD_TTL_SECONDS);

                    // Hosted PayMongo Gateway v2: Generates direct GCash, Maya, Card, or GrabPay checkout session
                    $payMongoGateway = app(\App\Services\Gateways\PayMongoGateway::class);
                    $checkoutResult = $payMongoGateway->createCheckoutSession($booking, $downpaymentAmount);

                    if (!$checkoutResult['success']) {
                        $errMsg = is_array($checkoutResult['error'] ?? null)
                            ? ($checkoutResult['error']['errors'][0]['detail'] ?? 'PayMongo session creation failed.')
                            : ($checkoutResult['error'] ?? 'Unable to connect to PayMongo.');
                        throw new Exception("PayMongo Error: " . $errMsg);
                    }

                    $payment->update([
                        'paymongo_resource_id' => $checkoutResult['checkout_id'],
                    ]);

                    BookingStatusLog::create([
                        'booking_id' => $booking->id,
                        'old_status' => 'initiated',
                        'new_status' => 'pending_downpayment',
                        'changed_by' => null,
                        'note' => "Reservation created. Awaiting downpayment of ₱" . number_format($downpaymentAmount, 2) . " via PayMongo Hosted Checkout.",
                        'created_at' => now(),
                    ]);

                    AuditLogger::log(
                        'BOOKING_CREATED',
                        "New booking #{$booking->booking_number} created for {$booking->contact_name} ({$paxCount} pax, {$booking->class_type}). Awaiting PayMongo checkout.",
                        null,
                        $booking->contact_name,
                        $request
                    );

                    DB::commit();

                    app(\App\Services\AdminNotificationService::class)->newBooking($booking->fresh());

                    return response()->json([
                        'success' => true,
                        'is_paymongo_redirect' => true,
                        'checkout_url' => $checkoutResult['checkout_url'],
                        'checkout_id' => $checkoutResult['checkout_id'],
                        'booking_number' => $booking->booking_number,
                        'pin' => $booking->pin,
                        'booking_id' => $booking->id,
                        'downpayment_paid' => $downpaymentAmount,
                        'downpayment_due' => $downpaymentAmount,
                        'balance_due' => $balanceAmount,
                        'manage_url' => route('manage.show', ['booking_number' => $booking->booking_number, 'pin' => $booking->pin]),
                    ]);
                } catch (Exception $e) {
                    DB::rollBack();
                    $this->slotReservationService->releaseHold($startDate, $bookingNumber);
                    return response()->json([
                        'success' => false,
                        'message' => 'An error occurred while setting up your PayMongo payment: ' . $e->getMessage(),
                    ], 500);
                }
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return response()->json([
                'success' => false,
                'message' => 'The booking system is currently processing high-volume simultaneous checkouts. Please retry in a few moments.',
            ], 429);
        }
    }
}
