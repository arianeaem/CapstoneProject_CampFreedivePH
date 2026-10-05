<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Support\Facades\Gate;
use App\Http\Requests\Admin\Users\UpdateUserRequest;
use App\Http\Requests\Admin\Users\StoreUserRequest;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function __construct(protected UserAccountService $accounts)
    {
    }

    /**
     * Stop a coach from being deactivated/removed while they still have upcoming batches.
     * Returns a redirect with the batches to reassign, or null when it is safe to continue.
     */
    protected function blockIfCoachHasUpcomingWork(User $user, string $action): ?RedirectResponse
    {
        $commitments = $this->accounts->upcomingCoachCommitments($user);
        if ($commitments->isEmpty()) {
            return null;
        }

        return back()
            ->with('error', "{$user->name} can't be {$action} yet because they still have upcoming batches. Reassign their students to other coaches first: " . $this->accounts->describeCommitments($commitments) . '.')
            ->with('blocked_coach', [
                'name' => $user->name,
                'action' => $action,
                'batches' => $commitments->map(fn ($c) => [
                    'batch_number' => $c['batch']->batch_number,
                    'date' => $c['batch']->formatted_date_range,
                    'students' => $c['students'],
                    'team_only' => $c['team_only'],
                ])->all(),
            ]);
    }

    /**
     * Display a listing of internal users.
     */
    public function index(Request $request): View
    {
        $currentUser = Auth::user();

        $query = User::query()->latest();

        // Search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Role filter
        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        // Status filter (removed/archived accounts only show when explicitly filtered)
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->where('status', '!=', 'archived');
        }

        $perPage = max(5, min(100, (int) $request->input('per_page', 10)));
        $users = $query->paginate($perPage)->withQueryString();

        $stats = [
            'total' => User::where('status', '!=', 'archived')->count(),
            'coaches' => User::where('role', 'coach')->where('status', '!=', 'archived')->count(),
            'admins' => User::whereIn('role', ['admin', 'owner'])->where('status', '!=', 'archived')->count(),
            'active' => User::where('status', 'active')->count(),
        ];

        return view('admin.users.index', compact('users', 'currentUser', 'stats'));

    }

    /**
     * Provision and store a new internal user account.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $currentUser = Auth::user();
        $validated = $request->validated();

        $fullName = $request->fullName() ?: ($validated['name'] ?? '');
        if (empty($fullName)) {
            return back()->withErrors(['first_name' => 'First and Last name are required.'])->withInput();
        }

        $tempPassword = !empty($validated['temp_password']) ? $validated['temp_password'] : ('TempPass' . mt_rand(1000, 9999) . '!');

        $user = User::create([
            'name' => $fullName,
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'role' => $validated['role'],
            'status' => 'active',
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
        ]);

        AuditLogger::log(
            'USER_CREATED',
            "New {$user->role} account created: {$user->email} (Name: {$user->name}) by {$currentUser->name} ({$currentUser->role})",
            $user,
            $currentUser->name,
            $request
        );

        return redirect()->route('admin.users.index')
            ->with('success', "Account for {$user->name} created successfully!")
            ->with('new_user_credentials', [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'role_label' => $user->role_badge['label'] ?? ucfirst($user->role),
                'temp_password' => $tempPassword,
                'login_url' => route('login'),
                'is_reset' => false,
            ]);
    }

    /**
     * Show the profile edit form for a user (Admin/Owner-managed only).
     */
    public function edit(User $user): View
    {
        Gate::authorize('update', $user);
        $currentUser = Auth::user();

        return view('admin.users.edit', compact('user', 'currentUser'));
    }

    /**
     * Update an internal user's profile details.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $currentUser = Auth::user();
        $validated = $request->validated();

        if ($user->isActive() && $validated['status'] === 'inactive' && ($blocked = $this->blockIfCoachHasUpcomingWork($user, 'deactivated'))) {
            return $blocked->withInput();
        }

        $fullName = $request->fullName() ?: ($validated['name'] ?? $user->name);

        $changes = [];
        if ($user->name !== $fullName) $changes[] = "Name: {$user->name} → {$fullName}";
        if ($user->email !== $validated['email']) $changes[] = "Email: {$user->email} → {$validated['email']}";
        if ($user->phone !== $validated['phone']) $changes[] = "Phone: {$user->phone} → {$validated['phone']}";
        if ($user->role !== $validated['role']) $changes[] = "Role: {$user->role} → {$validated['role']}";
        if ($user->status !== $validated['status']) $changes[] = "Status: {$user->status} → {$validated['status']}";

        $updateData = [
            'name' => $fullName,
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'role' => $validated['role'],
            'status' => $validated['status'],
        ];

        $hasPasswordReset = !empty($validated['new_password']);
        if ($hasPasswordReset) {
            $updateData['password'] = Hash::make($validated['new_password']);
            $updateData['must_change_password'] = true;
            $changes[] = "Password reset by admin (temporary password assigned)";
        }

        $user->update($updateData);

        $changeSummary = !empty($changes) ? implode(', ', $changes) : 'No field changes';

        AuditLogger::log(
            'USER_UPDATED',
            "Profile updated for {$user->email} by {$currentUser->name}: {$changeSummary}",
            $user,
            $currentUser->name,
            $request
        );

        $response = redirect()->route('admin.users.index')
            ->with('success', "Profile for {$user->name} updated successfully.");

        if ($hasPasswordReset) {
            $response->with('new_user_credentials', [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'role_label' => $user->role_badge['label'] ?? ucfirst($user->role),
                'temp_password' => $validated['new_password'],
                'login_url' => route('login'),
                'is_reset' => true,
            ]);
        }

        return $response;
    }

    /**
     * Toggle active/inactive account status.
     */
    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        $currentUser = Auth::user();

        // Prevent modifying self status
        if ($user->id === $currentUser->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        Gate::authorize('changeStatus', $user);

        if ($user->isActive() && ($blocked = $this->blockIfCoachHasUpcomingWork($user, 'deactivated'))) {
            return $blocked;
        }

        $wasArchived = $user->isArchived();
        $newStatus = $user->isActive() ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        AuditLogger::log(
            $wasArchived ? 'USER_RESTORED' : 'USER_STATUS_TOGGLED',
            ($wasArchived ? 'Removed account restored' : "Status changed to {$newStatus}") . " for user: {$user->email} by {$currentUser->name}",
            $user,
            $currentUser->name,
            $request
        );

        return back()->with('success', $wasArchived
            ? "Account {$user->name} has been restored and is active again."
            : "Account {$user->name} is now {$newStatus}.");
    }

    /**
     * "Remove" an internal user account (Owner, or Admin for Coaches).
     * Nothing is deleted: the account is archived so all history stays, login is blocked,
     * and the person is emailed. Coaches with upcoming batches must be reassigned first.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $currentUser = Auth::user();

        // Prevent self-deletion
        if ($user->id === $currentUser->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        Gate::authorize('delete', $user);

        if ($user->isArchived()) {
            return back()->with('error', "{$user->name}'s account is already removed.");
        }

        if ($blocked = $this->blockIfCoachHasUpcomingWork($user, 'removed')) {
            return $blocked;
        }

        $this->accounts->archive($user, $currentUser);

        AuditLogger::log(
            'USER_REMOVED',
            "User account removed (archived, data kept): {$user->email} ({$user->name}, Role: {$user->role}) by {$currentUser->name}",
            $user,
            $currentUser->name,
            $request
        );

        return redirect()->route('admin.users.index')
            ->with('success', "{$user->name}'s account has been removed. Their records are kept and they have been notified by email.");
    }
}
