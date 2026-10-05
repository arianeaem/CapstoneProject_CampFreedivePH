<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Support\Facades\Gate;
use App\Http\Controllers\Controller;
use App\Models\Coach;
use App\Models\DeactivationRequest;
use App\Services\AuditLogger;
use App\Services\DeactivationService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Http\Requests\Admin\Coaches\ProposeDeactivationRequest;

class DeactivationController extends Controller
{
    public function __construct(
        protected DeactivationService $deactivationService
    ) {}

    /**
     * Display the pending deactivation requests queue.
     */
    public function index(Request $request): View
    {
        $pendingRequests = DeactivationRequest::with(['coach', 'requester'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        $perPage = max(5, min(100, (int) $request->input('per_page', 10)));
        $processedRequests = DeactivationRequest::with(['coach', 'requester', 'resolver'])
            ->whereIn('status', ['confirmed', 'dismissed'])
            ->latest('resolved_at')
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.coaches.deactivations', compact('pendingRequests', 'processedRequests'));
    }


    /**
     * Propose coach deactivation (Admin or Owner).
     */
    public function propose(ProposeDeactivationRequest $request, Coach $coach): RedirectResponse
    {
        $currentUser = Auth::user();

        $validated = $request->validated();

        try {
            $this->deactivationService->propose($coach, $currentUser, $validated['reason'] ?? null);

            AuditLogger::log(
                'COACH_DEACTIVATION_PROPOSED',
                "Deactivation proposed for Coach {$coach->full_name} by {$currentUser->name}" . (!empty($validated['reason']) ? " (Reason: {$validated['reason']})" : ''),
                $currentUser,
                $currentUser->name,
                $request
            );

            return back()->with('info', "Deactivation requested for {$coach->full_name}. Awaiting Camp Owner confirmation.");
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Confirm coach deactivation (Owner only).
     */
    public function confirm(Request $request, DeactivationRequest $deactivationRequest): RedirectResponse
    {
        $currentUser = Auth::user();

        Gate::authorize('confirm', $deactivationRequest);

        $result = $this->deactivationService->confirm($deactivationRequest, $currentUser);

        if (!$result['success']) {
            return back()->with('error', $result['message']);
        }

        AuditLogger::log(
            'COACH_DEACTIVATION_CONFIRMED',
            "Deactivation confirmed for Coach {$deactivationRequest->coach->full_name} by Camp Owner {$currentUser->name}",
            $currentUser,
            $currentUser->name,
            $request
        );

        return back()->with('success', $result['message']);
    }

    /**
     * Dismiss coach deactivation request (Owner only).
     */
    public function dismiss(Request $request, DeactivationRequest $deactivationRequest): RedirectResponse
    {
        $currentUser = Auth::user();

        Gate::authorize('dismiss', $deactivationRequest);

        $this->deactivationService->dismiss($deactivationRequest, $currentUser);

        AuditLogger::log(
            'COACH_DEACTIVATION_DISMISSED',
            "Deactivation dismissed for Coach {$deactivationRequest->coach->full_name} by Camp Owner {$currentUser->name}",
            $currentUser,
            $currentUser->name,
            $request
        );

        return back()->with('info', "Deactivation request for {$deactivationRequest->coach->full_name} was dismissed. Coach remains Active.");
    }
}
