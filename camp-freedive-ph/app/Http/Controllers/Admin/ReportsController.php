<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Display the multi-tab Reports and Analytics dashboard.
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

        // Active tab: default to financial for owner, bookings for admin
        $activeTab = $request->input('tab', $isOwner ? 'financial' : 'bookings');
        if (!in_array($activeTab, ['financial', 'bookings', 'operations'], true) || (!$isOwner && $activeTab === 'financial')) {
            $activeTab = $isOwner ? 'financial' : 'bookings';
        }

        return view('admin.reports.index', compact('user', 'isOwner', 'range', 'data', 'activeTab'));
    }

    /**
     * Stream CSV export for the selected dataset and date range.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = Auth::user();
        $isOwner = ($user->role === 'owner');

        $type = $request->input('type', 'revenue');
        $preset = $request->input('preset', 'this_month');
        $customStart = $request->input('start_date');
        $customEnd = $request->input('end_date');

        $range = $this->analyticsService->resolveDateRange($preset, $customStart, $customEnd);

        return $this->exportService->streamCsv($type, $range, $isOwner);
    }

    /**
     * Display the printable executive report format (for print & PDF).
     */
    public function printSummary(Request $request): View
    {
        $user = Auth::user();
        $isOwner = ($user->role === 'owner');

        $preset = $request->input('preset', 'this_month');
        $customStart = $request->input('start_date');
        $customEnd = $request->input('end_date');

        $range = $this->analyticsService->resolveDateRange($preset, $customStart, $customEnd);
        $data = $this->analyticsService->getAnalyticsReport($range, $isOwner);

        return view('admin.reports.print_summary', compact('user', 'isOwner', 'range', 'data'));
    }
}
