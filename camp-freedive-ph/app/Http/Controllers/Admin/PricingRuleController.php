<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Pricing\UpdatePricingRuleRequest;
use App\Http\Controllers\Controller;
use App\Models\BookingPriceAdjustment;
use App\Models\PricingRule;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Http\Requests\Admin\Pricing\StorePricingRuleRequest;

class PricingRuleController extends Controller
{
    /**
     * Page 1: pricing rules list
     */
    public function index(Request $request): View
    {
        $query = PricingRule::withCount('adjustments')->with('creator');

        // Filters
        if ($request->filled('rule_type') && $request->rule_type !== 'all') {
            $query->where('rule_type', $request->rule_type);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('applies_to') && $request->applies_to !== 'all') {
            $query->where('applies_to', $request->applies_to);
        }

        // Sorting
        $sort = $request->get('sort', 'priority');
        match ($sort) {
            'recent' => $query->orderBy('id', 'desc'),
            'triggered' => $query->orderBy('adjustments_count', 'desc')->orderBy('priority', 'asc'),
            default => $query->orderBy('priority', 'asc')->orderBy('id', 'asc'),
        };

        $rules = $query->paginate(15)->withQueryString();

        // Numbers for the summary cards
        $totalRules = PricingRule::count();
        $activeRules = PricingRule::where('status', 'active')->count();
        $totalTriggered = BookingPriceAdjustment::count();
        $netRevenueImpact = BookingPriceAdjustment::sum('adjustment_amount');

        return view('admin.pricing.index', compact(
            'rules',
            'totalRules',
            'activeRules',
            'totalTriggered',
            'netRevenueImpact'
        ));
    }

    /**
     * Page 2: create rule
     */
    public function create(): View
    {
        return view('admin.pricing.create');
    }

    /**
     * Save a new pricing rule.
     */
    public function store(StorePricingRuleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $validated['priority'] = $validated['priority'] ?? 1;
        $validated['created_by'] = Auth::id();

        $rule = PricingRule::create($validated);

        AuditLogger::log(
            'PRICING_RULE_CREATED',
            "Created pricing rule '{$rule->name}' ({$rule->formatted_adjustment}, {$rule->condition_summary})",
            Auth::user(),
            Auth::user()->name,
            $request
        );

        return redirect()->route('admin.pricing.index')
            ->with('success', "Pricing rule '{$rule->name}' created successfully.");
    }

    /**
     * Page 2: edit rule
     */
    public function edit(PricingRule $rule): View
    {
        return view('admin.pricing.edit', compact('rule'));
    }

    /**
     * Save changes to a pricing rule.
     */
    public function update(UpdatePricingRuleRequest $request, PricingRule $rule): RedirectResponse
    {
        $validated = $request->validated();

        $validated['priority'] = $validated['priority'] ?? 1;

        $rule->update($validated);

        AuditLogger::log(
            'PRICING_RULE_UPDATED',
            "Updated pricing rule '{$rule->name}' ({$rule->formatted_adjustment}, {$rule->condition_summary})",
            Auth::user(),
            Auth::user()->name,
            $request
        );

        return redirect()->route('admin.pricing.index')
            ->with('success', "Pricing rule '{$rule->name}' updated successfully.");
    }

    /**
     * Switch a rule on/off
     */
    public function toggleStatus(Request $request, PricingRule $rule): JsonResponse|RedirectResponse
    {
        $newStatus = ($rule->status === 'active') ? 'inactive' : 'active';
        $rule->update(['status' => $newStatus]);

        AuditLogger::log(
            'PRICING_RULE_STATUS_TOGGLED',
            "Changed status of rule '{$rule->name}' to " . strtoupper($newStatus),
            Auth::user(),
            Auth::user()->name,
            $request
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'new_status' => $newStatus,
                'message' => "Rule status updated to {$newStatus}.",
            ]);
        }

        return back()->with('success', "Rule '{$rule->name}' is now " . ucfirst($newStatus) . '.');
    }

    /**
     * Soft delete a rule (old bookings still show which rule was used).
     */
    public function destroy(Request $request, PricingRule $rule): RedirectResponse
    {
        $triggeredCount = $rule->adjustments()->count();
        $ruleName = $rule->name;

        $rule->delete();

        AuditLogger::log(
            'PRICING_RULE_DELETED',
            "Deleted pricing rule '{$ruleName}' (Affected {$triggeredCount} past bookings)",
            Auth::user(),
            Auth::user()?->name ?: 'System',
            $request
        );

        $message = $triggeredCount > 0
            ? "Pricing rule '{$ruleName}' has been archived. Past booking breakdown records have been preserved for auditing."
            : "Pricing rule '{$ruleName}' was deleted.";

        return redirect()->route('admin.pricing.index')->with('success', $message);
    }

    /**
     * Page 3: bookings that used this rule
     */
    public function triggered(Request $request, PricingRule $rule): View
    {
        $query = $rule->adjustments()->with(['booking.participants']);

        if ($request->filled('date_from')) {
            $query->whereHas('booking', function ($q) use ($request) {
                $q->whereDate('start_date', '>=', $request->date_from);
            });
        }

        if ($request->filled('date_to')) {
            $query->whereHas('booking', function ($q) use ($request) {
                $q->whereDate('start_date', '<=', $request->date_to);
            });
        }

        $adjustments = $query->latest('id')->paginate(20)->withQueryString();

        $totalCount = $rule->adjustments()->count();
        $totalImpact = $rule->adjustments()->sum('adjustment_amount');

        return view('admin.pricing.triggered', compact('rule', 'adjustments', 'totalCount', 'totalImpact'));
    }
}
