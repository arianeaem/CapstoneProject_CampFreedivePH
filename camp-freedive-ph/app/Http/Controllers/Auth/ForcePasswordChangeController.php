<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ForcePasswordChangeController extends Controller
{
    /**
     * Show the mandatory password change form.
     */
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        if (!$user || !$user->must_change_password) {
            return match ($user?->role) {
                'owner' => redirect()->route('owner.dashboard'),
                'admin' => redirect()->route('admin.dashboard'),
                'coach' => redirect()->route('coach.dashboard'),
                default => redirect()->route('login'),
            };
        }

        return view('auth.force-password-change', compact('user'));
    }

    /**
     * Update temporary password to a new secure password.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'password.different' => 'Your new password cannot be the same as your temporary password.',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors([
                'current_password' => 'The current temporary password you entered is incorrect.',
            ]);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'must_change_password' => false,
        ]);

        AuditLogger::log('PASSWORD_CHANGED', "Temporary password updated on first login by: {$user->email} (Role: {$user->role})", $user, $user->name, $request);

        $redirectRoute = match ($user->role) {
            'owner' => 'owner.dashboard',
            'admin' => 'admin.dashboard',
            'coach' => 'coach.dashboard',
            default => 'login',
        };

        return redirect()->route($redirectRoute)->with('success', 'Your password has been successfully updated! Welcome to Camp FreedivePH.');
    }
}
