<?php

namespace App\Http\Controllers;

use App\Http\Requests\Booking\StorePublicBookingRequest;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\PricingRuleEngine;
use App\Services\SlotReservationService;
use App\Services\WeatherSafetyService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Http\Requests\Booking\CheckWeatherRequest;
use App\Http\Requests\Booking\PricingQuoteRequest;

/**
 * Public booking page for participants.
 *
 * - shows the booking form (booking.create)
 * - checks the weather for the picked dates
 * - max 45 people per weekend trip (boat limit and 1 coach for every 4 participants)
 * - holds the slots for 15 minutes during checkout so two people can't take the same slots
 * - new bookings start as pending_downpayment and the slots are released if not paid in time
 */
class BookingController extends Controller
{
    public function __construct(
        protected WeatherSafetyService $weatherSafetyService,
        protected PricingRuleEngine $pricingRuleEngine,
        protected SlotReservationService $slotReservationService
    ) {}

    /**
     * Show the booking form.
     *
     * @param Request $request can have ?class= to pre-select a class
     * @return View
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
            // Only shown to the person whose session owns the booking (set after payment
            // or a booking number + PIN lookup). The booking number alone is not enough.
            $confirmedBooking = Booking::with(['participants', 'priceAdjustments', 'payments'])
                ->where('booking_number', (string) $request->query('confirmed'))
                ->first();

            if ($confirmedBooking && session('auth_booking_id') === $confirmedBooking->id) {
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
                    'Boat dive (optional +₱600/pax)',
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
                    'Boat dive (optional +₱600/pax)',
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
                    'Boat dive (optional +₱600/pax)',
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

        $bookAgain = session()->pull('book_again');

        return view('booking.create', compact(
            'bookAgain',
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
     * Get the weather forecast for the picked dates.
     *
     * @param Request $request needs start_date and end_date (YYYY-MM-DD)
     * @return JsonResponse safety rating and weather readings
     */
    public function checkWeather(CheckWeatherRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $forecast = $this->weatherSafetyService->getForecast(
            $validated['start_date'],
            $validated['end_date']
        );

        return response()->json($forecast);
    }

    /**
     * Get the price quote for the booking form.
     * Uses PricingRuleEngine for early bird, certified diver and group discounts.
     *
     * @param Request $request class_type, start_date, is_certified_diver, participants_count
     * @return JsonResponse base price, discounts, subtotal and fees
     */
    public function getPricingQuote(PricingQuoteRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $quote = $this->pricingRuleEngine->evaluate(
            $validated['class_type'],
            $validated['start_date'],
            (bool) ($validated['is_certified_diver'] ?? false),
            (int) ($validated['participants_count'] ?? 1)
        );

        return response()->json($this->issueQuoteToken($quote));
    }

    /** Minutes a quoted price stays locked: the same as the checkout timer. */
    private const QUOTE_LOCK_MINUTES = 15;

    /**
     * Remember a quote so checkout charges exactly what the guest was shown.
     */
    private function issueQuoteToken(array $quote): array
    {
        $token = Str::random(40);
        Cache::put("pricing_quote:{$token}", $quote, now()->addMinutes(self::QUOTE_LOCK_MINUTES));

        return $quote + ['quote_token' => $token, 'quote_locked_minutes' => self::QUOTE_LOCK_MINUTES];
    }

    /**
     * The locked quote for this token, resized to the final group size.
     * Null when the token is missing, expired, or was issued for another class, date or certification.
     */
    private function lockedQuote(?string $token, string $classType, string $startDate, bool $isCertified, int $paxCount): ?array
    {
        $quote = $token ? Cache::get("pricing_quote:{$token}") : null;

        if (! is_array($quote)
            || strtolower($quote['class_type']) !== strtolower($classType)
            || $quote['dive_date'] !== $startDate
            || (bool) $quote['is_certified'] !== $isCertified) {
            return null;
        }

        $quote['pax_count'] = $paxCount;
        $quote['subtotal'] = round($quote['adjusted_price_per_pax'] * $paxCount, 2);
        $quote['adjustments'] = array_map(
            fn ($a) => ['total_delta' => round($a['delta_per_pax'] * $paxCount, 2)] + $a,
            $quote['adjustments']
        );

        return $quote;
    }

    /**
     * Save a new booking.
     *
     * Steps:
     * 1. validate the participants and contact details
     * 2. check the 45 people limit
     * 3. compute the price and add the LGU and environmental fees
     * 4. make the booking number (e.g. CFP-2026-1234) and 4-digit PIN
     * 5. save the booking and payment as pending_downpayment
     *
     * @param Request $request booking form data
     * @return JsonResponse booking number, PIN, downpayment and payment link
     */
    public function store(StorePublicBookingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Check the weather again here. The check on the page can be skipped,
        // so we don't trust it for Critical Risk.
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

        // New flow (Option B, behind a rollout flag): an unpaid checkout creates a checkout intent, never a booking
        if (config('checkout.intents_enabled')) {
            return $this->startCheckoutIntent($request, $validated, $contactName, $paxCount, $startDate, $endDate, $isCertified);
        }

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
                // Max 45 people per weekend trip (boat limit and 1 coach for every 4 participants).
                // Counts saved bookings and the 15-minute checkout holds.
                $availableSlots = $this->slotReservationService->getAvailableSlots($startDate);

                if ($paxCount > $availableSlots) {
                    return response()->json([
                        'success' => false,
                        'message' => "Capacity limit reached for {$startDate}. Only {$availableSlots} slot(s) remaining for this weekend trip. Please select another date or reduce group size.",
                    ], 422);
                }

                $charges = $this->calculateCharges($validated, $startDate, $isCertified, $paxCount);
                if ($charges instanceof JsonResponse) {
                    return $charges; // price changed: the guest confirms the new price first
                }
                [
                    'quote' => $quote, 'class_price' => $classPrice, 'subtotal' => $subtotal,
                    'lgu_fee' => $lguFee, 'environmental_fee' => $envFee, 'carpool_fee' => $carpoolFee, 'boat_dive_fee' => $boatDiveFee,
                    'total_amount' => $totalAmount, 'downpayment_amount' => $downpaymentAmount, 'balance_amount' => $balanceAmount,
                ] = $charges;

                // Put the booking in the batch for these dates
                $batch = app(\App\Services\BatchManagementService::class)->findOrCreateBatchForDates($startDate, $endDate);

                // 4-digit PIN so the participant can look up the booking without an account
                $bookingNumber = 'CFP-' . date('Y') . '-' . strtoupper(Str::random(5));
                $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

                // Save everything in one transaction. If the PayMongo session fails,
                // nothing gets saved.
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
                        'contact_name' => $contactName ?: 'Participant',
                        'contact_email' => $validated['contact_email'],
                        'contact_phone' => $validated['contact_phone'],
                        'contact_facebook' => $validated['contact_facebook'] ?? null,
                        'status' => 'pending_downpayment',
                    ] + $request->guardianFields());

                    // Save each participant's health info
                    foreach ($this->participantRecords($validated['participants'], $classPrice) as $participant) {
                        $booking->participants()->create($participant);
                    }

                    // Save the discounts used at checkout, so later rule changes
                    // don't change the price of this booking
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

                    // Payment link expires in 24 hours
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

                    // Hold the slots for 15 minutes during checkout
                    $this->slotReservationService->acquireHold($startDate, $bookingNumber, $paxCount, SlotReservationService::DEFAULT_HOLD_TTL_SECONDS);

                    // Create the PayMongo checkout (GCash, Maya, card, GrabPay)
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

    /**
     * Checkout-intent flow: price the booking, hold the slots on a checkout intent and send the guest
     * to PayMongo. No booking number or PIN exists yet; those come only after the payment is verified.
     */
    private function startCheckoutIntent(StorePublicBookingRequest $request, array $validated, string $contactName, int $paxCount, string $startDate, string $endDate, bool $isCertified): JsonResponse
    {
        $charges = $this->calculateCharges($validated, $startDate, $isCertified, $paxCount);
        if ($charges instanceof JsonResponse) {
            return $charges; // price changed: the guest confirms the new price first
        }

        $quote = $charges['quote'];
        $contact = [
            'name' => $contactName ?: 'Participant',
            'first_name' => trim($validated['contact_first_name'] ?? ''),
            'middle_name' => !empty($validated['contact_no_middle_name']) ? '' : trim($validated['contact_middle_name'] ?? ''),
            'last_name' => trim($validated['contact_last_name'] ?? ''),
            'suffix' => trim($validated['contact_suffix'] ?? ''),
            'email' => $validated['contact_email'],
            'phone' => $validated['contact_phone'],
            'facebook' => $validated['contact_facebook'] ?? null,
        ];

        $result = app(\App\Services\Checkout\CheckoutService::class)->start([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'pax_count' => $paxCount,
            'class_type' => $validated['class_type'],
            'pickup_option' => $validated['pickup_option'],
            'total_amount' => $charges['total_amount'],
            'downpayment_amount' => $charges['downpayment_amount'],
        ], [
            'booking' => [
                'class_type' => $validated['class_type'],
                'is_certified_diver' => $isCertified,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'pickup_option' => $validated['pickup_option'],
                'pickup_location' => $validated['pickup_location'] ?? null,
                'boat_dive' => !empty($validated['boat_dive']),
            ],
            'contact' => $contact,
            'guardian' => $request->guardianFields(),
            'participants' => $this->participantRecords($validated['participants'], $charges['class_price']),
            // The quote that is charged; becomes the booking's price-adjustment rows after payment
            'pricing' => [
                'pricing_version' => 1,
                'quote_source' => $charges['quote_locked'] ? 'locked_quote' : 'live_evaluation',
                'quoted_at' => now()->toIso8601String(),
                'base_price_per_pax' => $quote['base_price_per_pax'],
                'adjusted_price_per_pax' => $quote['adjusted_price_per_pax'],
                'delta_per_pax' => $quote['delta_per_pax'] ?? null,
                'subtotal' => $quote['subtotal'],
                'was_clamped' => $quote['was_clamped'] ?? null,
                'adjustments' => array_values(array_map(fn ($adj) => [
                    'rule_id' => $adj['rule_id'],
                    'rule_name' => $adj['rule_name'],
                    'rule_type' => $adj['rule_type'],
                    'condition_summary' => $adj['condition_summary'],
                    'delta_per_pax' => $adj['delta_per_pax'],
                    'base_price' => $quote['base_price_per_pax'],
                    'adjusted_price' => $quote['adjusted_price_per_pax'],
                ], $quote['adjustments'])),
                'fees' => [
                    'lgu' => $charges['lgu_fee'],
                    'environmental' => $charges['environmental_fee'],
                    'carpool' => $charges['carpool_fee'],
                    'boat_dive' => $charges['boat_dive_fee'],
                ],
                'total_amount' => $charges['total_amount'],
                'downpayment_amount' => $charges['downpayment_amount'],
                'balance_amount' => $charges['balance_amount'],
                'currency' => 'PHP',
            ],
        ]);

        if (! $result['ok']) {
            return response()->json(['success' => false, 'message' => $result['message']], $result['status']);
        }

        // No booking number, PIN, booking id or manage link before payment
        return response()->json([
            'success' => true,
            'is_paymongo_redirect' => true,
            'checkout_reference' => $result['intent']->reference,
            'checkout_url' => $result['checkout_url'],
            'downpayment_due' => (float) $charges['downpayment_amount'],
            'balance_due' => (float) $charges['balance_amount'],
        ]);
    }

    /**
     * Price, fees and downpayment, all worked out on the server (amounts sent by the browser are ignored).
     * Charges the price the guest was shown (locked for 15 minutes). Without a valid lock, uses today's
     * price, but if it differs from what the guest saw, returns a 409 so they confirm the new price first.
     */
    private function calculateCharges(array $validated, string $startDate, bool $isCertified, int $paxCount): array|JsonResponse
    {
        $quote = $this->lockedQuote($validated['quote_token'] ?? null, $validated['class_type'], $startDate, $isCertified, $paxCount);
        $quoteLocked = (bool) $quote;

        if (! $quote) {
            $quote = $this->pricingRuleEngine->evaluate($validated['class_type'], $startDate, $isCertified, $paxCount);
            $shownPrice = $validated['quoted_price_per_pax'] ?? null;

            if ($shownPrice !== null && abs((float) $shownPrice - $quote['adjusted_price_per_pax']) >= 0.01) {
                return response()->json([
                    'success' => false,
                    'price_changed' => true,
                    'message' => 'The price for this date changed from ₱' . number_format((float) $shownPrice, 2)
                        . ' to ₱' . number_format($quote['adjusted_price_per_pax'], 2) . ' per person. Please confirm the new price to continue.',
                    'quote' => $this->issueQuoteToken($quote),
                ], 409);
            }
        }

        $classPrice = $quote['adjusted_price_per_pax'];
        $subtotal = $quote['subtotal'];

        // Fees:
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

        // Downpayment (default: carpool P3,000/head, own transport P2,000/head):
        $dpCarpool = (float) ($settingService->get('program_pricing.downpayment_carpool', 3000.00) ?? 3000.00);
        $dpOwn = (float) ($settingService->get('program_pricing.downpayment_own_transpo', 2000.00) ?? 2000.00);

        $downpaymentPerHead = ($validated['pickup_option'] === 'carpool') ? $dpCarpool : $dpOwn;
        $downpaymentAmount = min($totalAmount, $downpaymentPerHead * $paxCount);

        return [
            'quote' => $quote,
            'quote_locked' => $quoteLocked,
            'class_price' => $classPrice,
            'subtotal' => $subtotal,
            'lgu_fee' => $lguFee,
            'environmental_fee' => $envFee,
            'carpool_fee' => $carpoolFee,
            'boat_dive_fee' => $boatDiveFee,
            'total_amount' => $totalAmount,
            'downpayment_amount' => $downpaymentAmount,
            'balance_amount' => max(0, $totalAmount - $downpaymentAmount),
        ];
    }

    /** Participant rows (name, age, health info and price) for the booking form's participants. */
    private function participantRecords(array $participants, float $classPrice): array
    {
        return array_map(function (array $pData) use ($classPrice) {
            $pfn = trim($pData['first_name'] ?? '');
            $pmn = (!empty($pData['no_middle_name'])) ? '' : trim($pData['middle_name'] ?? '');
            $pln = trim($pData['last_name'] ?? '');
            $psuf = trim($pData['suffix'] ?? '');
            if ($psuf === 'None' || $psuf === 'none') {
                $psuf = '';
            }
            $assembledPName = implode(' ', array_filter([$pfn, $pmn, $pln, $psuf]));
            $pName = !empty($pData['name']) ? $pData['name'] : $assembledPName;

            return [
                'name' => $pName ?: 'Participant',
                'birthdate' => !empty($pData['birthdate']) ? $pData['birthdate'] : null,
                'gender' => $pData['gender'] ?? null,
                'age' => !empty($pData['birthdate'])
                    ? Carbon::parse($pData['birthdate'])->age
                    : (int) $pData['age'],
                'health_condition' => $pData['health_condition'] ?? 'None',
                'swimmer_status' => $pData['swimmer_status'] ?? 'beginner',
                'price_per_person' => $classPrice,
            ];
        }, $participants);
    }
}
