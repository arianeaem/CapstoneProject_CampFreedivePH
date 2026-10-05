<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SystemSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Http\Requests\Admin\Settings\UpdateProgramsRequest;
use App\Http\Requests\Admin\Settings\UpdateDepositsRequest;
use App\Http\Requests\Admin\Settings\UpdateAddonsRequest;
use App\Http\Requests\Admin\Settings\UpdateOperationsRequest;
use App\Http\Requests\Admin\Settings\UpdateCancellationPolicyRequest;

class SettingsController extends Controller
{
    public function __construct(
        protected SystemSettingService $settingService
    ) {}

    /**
     * Display the Central Settings Directory (PayMongo style master hub).
     */
    public function index(Request $request): View
    {
        $currentUser = Auth::user();
        $groupedSettings = $this->settingService->all();

        $stats = [
            'total_users' => User::count(),
            'active_coaches' => User::where('role', 'coach')->where('status', 'active')->count(),
            'last_audit_log' => AuditLog::latest('created_at')->first(),
        ];

        return view('admin.settings.index', compact('groupedSettings', 'currentUser', 'stats'));
    }

    /**
     * Edit Programs, Class Rates, Inclusions & Exclusions.
     */
    public function editPrograms(): View
    {
        $currentUser = Auth::user();
        $settings = [
            'base_price_discovery' => $this->settingService->get('program_pricing.base_price_discovery', 4250.00),
            'base_price_fundive_cert' => $this->settingService->get('program_pricing.base_price_fundive_cert', 2500.00),
            'base_price_fundive_noncert' => $this->settingService->get('program_pricing.base_price_fundive_noncert', 3300.00),
            'base_price_refinement' => $this->settingService->get('program_pricing.base_price_refinement', 4100.00),
            'dynamic_pricing_cap_percent' => $this->settingService->get('program_pricing.dynamic_pricing_cap_percent', 30.00),
            'discovery_inclusions' => $this->settingService->get('program_pricing.discovery_inclusions', []),
            'discovery_exclusions' => $this->settingService->get('program_pricing.discovery_exclusions', []),
            'fundive_inclusions' => $this->settingService->get('program_pricing.fundive_inclusions', []),
            'fundive_exclusions' => $this->settingService->get('program_pricing.fundive_exclusions', []),
            'refinement_inclusions' => $this->settingService->get('program_pricing.refinement_inclusions', []),
            'refinement_exclusions' => $this->settingService->get('program_pricing.refinement_exclusions', []),
        ];

        return view('admin.settings.programs', compact('currentUser', 'settings'));
    }

    /**
     * Update Programs, Class Rates, Inclusions & Exclusions.
     */
    public function updatePrograms(UpdateProgramsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $parseLines = fn(?string $text) => array_values(array_filter(
            array_map('trim', explode("\n", $text ?? '')),
            fn($line) => $line !== ''
        ));

        $settingsToUpdate = [
            'program_pricing.base_price_discovery' => number_format((float) $validated['base_price_discovery'], 2, '.', ''),
            'program_pricing.base_price_fundive_cert' => number_format((float) $validated['base_price_fundive_cert'], 2, '.', ''),
            'program_pricing.base_price_fundive_noncert' => number_format((float) $validated['base_price_fundive_noncert'], 2, '.', ''),
            'program_pricing.base_price_refinement' => number_format((float) $validated['base_price_refinement'], 2, '.', ''),
            'program_pricing.dynamic_pricing_cap_percent' => number_format((float) $validated['dynamic_pricing_cap_percent'], 2, '.', ''),
            'program_pricing.discovery_inclusions' => json_encode($parseLines($validated['discovery_inclusions'] ?? '')),
            'program_pricing.discovery_exclusions' => json_encode($parseLines($validated['discovery_exclusions'] ?? '')),
            'program_pricing.fundive_inclusions' => json_encode($parseLines($validated['fundive_inclusions'] ?? '')),
            'program_pricing.fundive_exclusions' => json_encode($parseLines($validated['fundive_exclusions'] ?? '')),
            'program_pricing.refinement_inclusions' => json_encode($parseLines($validated['refinement_inclusions'] ?? '')),
            'program_pricing.refinement_exclusions' => json_encode($parseLines($validated['refinement_exclusions'] ?? '')),
        ];

        $this->settingService->updateMany($settingsToUpdate, Auth::user());

        return back()->with('success', 'Program pricing, inclusions, and exclusions updated successfully.');
    }

    /**
     * Edit Reservation Downpayment Deposits.
     */
    public function editDeposits(): View
    {
        $currentUser = Auth::user();
        $settings = [
            'downpayment_carpool' => $this->settingService->get('program_pricing.downpayment_carpool', 3000.00),
            'downpayment_own_transpo' => $this->settingService->get('program_pricing.downpayment_own_transpo', 2000.00),
        ];

        return view('admin.settings.deposits', compact('currentUser', 'settings'));
    }

    /**
     * Update Reservation Downpayment Deposits.
     */
    public function updateDeposits(UpdateDepositsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $settingsToUpdate = [
            'program_pricing.downpayment_carpool' => number_format((float) $validated['downpayment_carpool'], 2, '.', ''),
            'program_pricing.downpayment_own_transpo' => number_format((float) $validated['downpayment_own_transpo'], 2, '.', ''),
            // Keep legacy single key synced for backwards compatibility
            'program_pricing.downpayment_amount' => number_format((float) $validated['downpayment_carpool'], 2, '.', ''),
        ];

        $this->settingService->updateMany($settingsToUpdate, Auth::user());

        return back()->with('success', 'Downpayment deposit rules updated successfully.');
    }

    /**
     * Edit Carpool, Boat Dive, LGU Fees & Pickup Locations.
     */
    public function editAddons(): View
    {
        $currentUser = Auth::user();
        $settings = [
            'carpool_fee_per_head' => $this->settingService->get('addons.carpool_fee_per_head', 1200.00),
            'boat_dive_fee_per_head' => $this->settingService->get('addons.boat_dive_fee_per_head', 600.00),
            'lgu_tourism_pass_fee' => $this->settingService->get('addons.lgu_tourism_pass_fee', 300.00),
            'environmental_fee' => $this->settingService->get('addons.environmental_fee', 50.00),
            'pickup_locations' => $this->settingService->get('addons.pickup_locations', []),
        ];

        return view('admin.settings.addons', compact('currentUser', 'settings'));
    }

    /**
     * Update Carpool, Boat Dive, LGU Fees & Pickup Locations.
     */
    public function updateAddons(UpdateAddonsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $locations = array_values(array_filter($validated['pickup_locations'] ?? [], fn($loc) => !empty(trim($loc['name'] ?? ''))));

        $settingsToUpdate = [
            'addons.carpool_fee_per_head' => number_format((float) $validated['carpool_fee_per_head'], 2, '.', ''),
            'addons.boat_dive_fee_per_head' => number_format((float) $validated['boat_dive_fee_per_head'], 2, '.', ''),
            'addons.lgu_tourism_pass_fee' => number_format((float) $validated['lgu_tourism_pass_fee'], 2, '.', ''),
            'addons.environmental_fee' => number_format((float) $validated['environmental_fee'], 2, '.', ''),
            'addons.pickup_locations' => json_encode($locations),
        ];

        $this->settingService->updateMany($settingsToUpdate, Auth::user());

        return back()->with('success', 'Transportation, add-on rates, and pickup hubs updated successfully.');
    }

    /**
     * Edit Camp Operations & Ratios.
     */
    public function editOperations(): View
    {
        $currentUser = Auth::user();
        $settings = [
            'max_batch_capacity' => $this->settingService->get('camp_operations.max_batch_capacity', 45),
            'coach_student_ratio' => $this->settingService->get('camp_operations.coach_student_ratio', 4),
            'min_coaches_per_batch' => $this->settingService->get('camp_operations.min_coaches_per_batch', 2),
        ];

        return view('admin.settings.operations', compact('currentUser', 'settings'));
    }

    /**
     * Update Camp Operations & Ratios.
     */
    public function updateOperations(UpdateOperationsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $settingsToUpdate = [
            'camp_operations.max_batch_capacity' => (int) $validated['max_batch_capacity'],
            'camp_operations.coach_student_ratio' => (int) $validated['coach_student_ratio'],
            'camp_operations.min_coaches_per_batch' => (int) $validated['min_coaches_per_batch'],
        ];

        $this->settingService->updateMany($settingsToUpdate, Auth::user());

        return back()->with('success', 'Camp operational capacity and coach ratios updated successfully.');
    }

    /**
     * Edit Booking & Cancellation Policies.
     */
    public function editCancellation(): View
    {
        $currentUser = Auth::user();
        $settings = [
            'full_refund_threshold_days' => $this->settingService->get('booking_cancellation.full_refund_threshold_days', 14),
            'reschedule_only_threshold_days' => $this->settingService->get('booking_cancellation.reschedule_only_threshold_days', 7),
        ];

        return view('admin.settings.cancellation', compact('currentUser', 'settings'));
    }

    /**
     * Update Booking & Cancellation Policies.
     */
    public function updateCancellation(UpdateCancellationPolicyRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        if ($validated['full_refund_threshold_days'] <= $validated['reschedule_only_threshold_days']) {
            return back()
                ->withInput()
                ->withErrors(['full_refund_threshold_days' => 'Full refund window (in days) must be strictly greater than the reschedule-only window.']);
        }

        $settingsToUpdate = [
            'booking_cancellation.full_refund_threshold_days' => (int) $validated['full_refund_threshold_days'],
            'booking_cancellation.reschedule_only_threshold_days' => (int) $validated['reschedule_only_threshold_days'],
        ];

        $this->settingService->updateMany($settingsToUpdate, Auth::user());

        return back()->with('success', 'Cancellation and rescheduling policies updated successfully.');
    }
}
