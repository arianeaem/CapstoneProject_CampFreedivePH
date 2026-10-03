<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingPriceAdjustment;
use App\Models\PricingRule;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PricingRuleController extends Controller
{
    /**
     * Page 1 - Pricing Rules List
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

        // Metrics Summary Cards
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
     * Page 2 - Rule Builder (Create)
     */
    public function create(): View
    {
        return view('admin.pricing.create');
    }

    /**
     * Store newly created pricing rule.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:pricing_rules,name',
            'description' => 'nullable|string|max:1000',
            'rule_type' => 'required|in:demand,seasonality,lead_time',
            'condition_operator' => 'nullable|required_if:rule_type,lead_time|in:<=,>=,<,>,==',
            'condition_value' => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($request) {
                    $type = $request->input('rule_type');
                    if ($type === 'demand' && !in_array($value, ['high', 'medium', 'low'])) {
                        $fail('The selected demand level is invalid. Must be High, Medium, or Low.');
                    } elseif ($type === 'seasonality' && !in_array($value, ['peak', 'shoulder', 'off_peak'])) {
                        $fail('The selected season is invalid. Must be Peak, Shoulder, or Off-Peak.');
                    } elseif ($type === 'lead_time' && (!is_numeric($value) || (int)$value < 0)) {
                        $fail('The lead time days must be a non-negative number.');
                    }
                }
            ],
            'applies_to' => 'required|in:all,discovery,fundive,refinement',
            'adjustment_type' => 'required|in:increase,decrease',
            'adjustment_method' => 'required|in:percentage,fixed',
            'adjustment_value' => 'required|numeric|min:0.01|max:50000',
            'priority' => 'nullable|integer|min:1|max:999',
            'status' => 'required|in:active,inactive',
        ]);

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
     * Page 2 - Rule Builder (Edit)
     */
    public function edit(PricingRule $rule): View
    {
        return view('admin.pricing.edit', compact('rule'));
    }

    /**
     * Update an existing pricing rule.
     */
    public function update(Request $request, PricingRule $rule): RedirectResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('pricing_rules')->ignore($rule->id),
            ],
            'description' => 'nullable|string|max:1000',
            'rule_type' => 'required|in:demand,seasonality,lead_time',
            'condition_operator' => 'nullable|required_if:rule_type,lead_time|in:<=,>=,<,>,==',
            'condition_value' => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($request) {
                    $type = $request->input('rule_type');
                    if ($type === 'demand' && !in_array($value, ['high', 'medium', 'low'])) {
                        $fail('The selected demand level is invalid.');
                    } elseif ($type === 'seasonality' && !in_array($value, ['peak', 'shoulder', 'off_peak'])) {
                        $fail('The selected season is invalid.');
                    } elseif ($type === 'lead_time' && (!is_numeric($value) || (int)$value < 0)) {
                        $fail('The lead time days must be a non-negative number.');
                    }
                }
            ],
            'applies_to' => 'required|in:all,discovery,fundive,refinement',
            'adjustment_type' => 'required|in:increase,decrease',
            'adjustment_method' => 'required|in:percentage,fixed',
            'adjustment_value' => 'required|numeric|min:0.01|max:50000',
            'priority' => 'nullable|integer|min:1|max:999',
            'status' => 'required|in:active,inactive',
        ]);

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
     * Inline Toggle Status (Active / Inactive)
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
     * Soft delete rule (Preserves historical booking audit breakdowns).
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
     * Page 3 - Bookings Triggered by Rule
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
