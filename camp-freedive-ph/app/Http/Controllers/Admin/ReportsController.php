<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReportsController extends Controller
{
    protected AnalyticsService $analyticsService;
    protected ExportService $exportService;

    public function __construct(AnalyticsService $analyticsService, ExportService $exportService)
    {
        $this->analyticsService = $analyticsService;
        $this->exportService = $exportService;
    }

    /**
     * Reports page (with tabs).
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        $isOwner = ($user->role === 'owner');

        $preset = $request->input('preset', 'this_month');
        $customStart = $request->input('start_date');
        $customEnd = $request->input('end_date');

        $range = $this->analyticsService->resolveDateRange($preset, $customStart, $customEnd);
        $data = $this->analyticsService->getAnalyticsReport($range, $isOwner);

        // Default tab: financial for owner, bookings for admin
        $activeTab = $request->input('tab', $isOwner ? 'financial' : 'bookings');
        if (!in_array($activeTab, ['financial', 'bookings', 'operations'], true) || (!$isOwner && $activeTab === 'financial')) {
            $activeTab = $isOwner ? 'financial' : 'bookings';
        }

        return view('admin.reports.index', compact('user', 'isOwner', 'range', 'data', 'activeTab'));
    }

    /**
     * Download the Excel file for the selected report and dates.
     */
    public function export(Request $request): Response
    {
        $user = Auth::user();
        $isOwner = ($user->role === 'owner');

        $type = $request->input('type', 'revenue');
        $preset = $request->input('preset', 'this_month');
        $customStart = $request->input('start_date');
        $customEnd = $request->input('end_date');

        $range = $this->analyticsService->resolveDateRange($preset, $customStart, $customEnd);

        return $this->exportService->download($type, $range, $isOwner);
    }

    /** Printable summaries: the overall one plus one per category. */
    public const PRINT_TYPES = ['overall', 'revenue', 'bookings', 'operations'];

    /**
     * Print version of the report (for print / PDF).
     */
    public function printSummary(Request $request): View
    {
        $user = Auth::user();
        $isOwner = ($user->role === 'owner');

        $validator = validator($request->only(['type', 'preset', 'start_date', 'end_date']), [
            'type' => ['nullable', 'string', Rule::in(self::PRINT_TYPES)],
            'preset' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('start_date') ? ['after_or_equal:start_date'] : []),
        ]);
        abort_if($validator->fails(), 422, $validator->errors()->first());

        $type = $request->input('type') ?: 'overall';
        if ($type === 'revenue' && !$isOwner) {
            abort(403, 'Revenue summaries are only available to owners.');
        }

        $preset = $request->input('preset') ?: 'this_month';
        $customStart = $request->input('start_date');
        $customEnd = $request->input('end_date');

        $range = $this->analyticsService->resolveDateRange($preset, $customStart, $customEnd);
        $data = $this->analyticsService->getAnalyticsReport($range, $isOwner);

        return view('admin.reports.print_summary', compact('user', 'isOwner', 'range', 'data', 'type'));
    }
}
