<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class LandingController extends Controller
{
    /**
     * Display the Camp FreedivePH landing page.
     */
    public function index(): View
    {
        $settingService = app(\App\Services\SystemSettingService::class);

        $discPrice = (float) ($settingService->get('program_pricing.base_price_discovery', 4250) ?? 4250);
        $funCertPrice = (float) ($settingService->get('program_pricing.base_price_fundive_cert', 2500) ?? 2500);
        $funNonCertPrice = (float) ($settingService->get('program_pricing.base_price_fundive_noncert', 3300) ?? 3300);
        $refPrice = (float) ($settingService->get('program_pricing.base_price_refinement', 4100) ?? 4100);

        $discInc = $settingService->get('program_pricing.discovery_inclusions', [
            '2 open water dives (2-3 hrs per session)',
            '1 pool session (10 ft deep pool access)',
            '2D1N shared AC room accommodation',
            'Lesson fee and coach fee',
            'Safety buoy set up',
            '3 full board meals',
            'Photos and videos',
            'Gears',
        ]);
        $discExc = $settingService->get('program_pricing.discovery_exclusions', [
            'Transportation (We arrange carpool)',
            'Boat dive (optional)',
            'Mabini LGU divepass',
        ]);

        $funInc = $settingService->get('program_pricing.fundive_inclusions', [
            '2 open water dives (2-3 hrs per session)',
            '1 pool session (10 ft deep pool access)',
            '2D1N shared AC room accommodation',
            'Safety coach fee',
            'Safety buoy set up',
            '3 full board meals',
            'Photos and videos',
            'Gears',
        ]);
        $funExc = $settingService->get('program_pricing.fundive_exclusions', [
            'Transportation (We arrange carpool)',
            'Boat dive (optional)',
            'Mabini LGU divepass',
        ]);

        $refInc = $settingService->get('program_pricing.refinement_inclusions', [
            '2 open water dives (2-3 hrs per session)',
            '1 pool session (10 ft deep pool access)',
            '2D1N shared AC room accommodation',
            '3 full board meals',
            'Safety buoy set up',
            'Photos and videos',
            'Coach fee',
            'Gears',
        ]);
        $refExc = $settingService->get('program_pricing.refinement_exclusions', [
            'Transportation (We arrange carpool)',
            'Boat dive (optional)',
            'Mabini LGU divepass',
        ]);

        $classes = [
            'discovery' => [
                'id' => 'discovery',
                'name' => 'Discovery',
                'category' => 'BEGINNER CLASS',
                'price' => $discPrice,
                'price_label' => number_format($discPrice) . ' php',
                'inclusions' => is_array($discInc) ? $discInc : [],
                'exclusions' => is_array($discExc) ? $discExc : [],
                'note' => 'Perfect for first-time divers. Solo joiners and non-swimmers welcome.',
                'prerequisite' => null,
            ],
            'fundive' => [
                'id' => 'fundive',
                'name' => 'Fundive',
                'category' => 'PREREQUISITE: DISCOVERY CLASS',
                'price_certified' => $funCertPrice,
                'price_non_certified' => $funNonCertPrice,
                'price_label' => 'For certified freedivers: ' . number_format($funCertPrice) . ' php (safety coach not included) | For non certified freedivers: ' . number_format($funNonCertPrice) . ' php',
                'inclusions' => is_array($funInc) ? $funInc : [],
                'exclusions' => is_array($funExc) ? $funExc : [],
                'note' => 'For divers ready to explore open water. Solo joiners welcome.',
                'prerequisite' => 'PREREQUISITE: DISCOVERY CLASS',
            ],
            'refinement' => [
                'id' => 'refinement',
                'name' => 'Refinement',
                'category' => 'PRACTICE DIVE',
                'price' => $refPrice,
                'price_label' => number_format($refPrice) . ' php',
                'inclusions' => is_array($refInc) ? $refInc : [],
                'exclusions' => is_array($refExc) ? $refExc : [],
                'note' => 'For divers looking to improve their skills. Solo joiners welcome.',
                'prerequisite' => 'Prerequisite: Discovery Class completion.',
            ],
        ];

        $faqs = [
            [
                'q' => 'Is this safe for someone who does not know how to swim?',
                'a' => 'Yes, our Discovery Class is open to solo joiners and non-swimmers. You will be guided closely with safety coach support and proper gear.',
            ],
            [
                'q' => 'What is the cancellation and reschedule policy?',
                'a' => 'More than 14 days before your dive date: Full downpayment refund or free reschedule. Within 7 to 14 days: Free reschedule to another available date. Within 7 days: Non-refundable and non-reschedulable unless an official storm warning / force majeure is active.',
            ],
            [
                'q' => 'How does transportation and carpool work?',
                'a' => 'We arrange carpools with pickup points in Monumento, Shell Tiendesitas, Market! Market!, Starmall Alabang, and Sto. Tomas Exit. If you choose carpool, the booking downpayment is 3,000 php per person. If you bring your own transpo, the downpayment is 2,000 php per person.',
            ],
            [
                'q' => 'What are the required local municipal fees?',
                'a' => 'Mabini LGU requires a Municipal Environmental Protection Fee and an LGU Dive Pass for marine conservation.',
            ],
        ];

        return view('landing', compact('classes', 'faqs'));
    }
}
