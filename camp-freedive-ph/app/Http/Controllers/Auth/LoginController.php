<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Http\Requests\Auth\LoginRequest;

class LoginController extends Controller
{
    /**
     * Staff login page.
     */
    public function showLoginForm(Request $request): View|RedirectResponse
    {
        if ($request->has('logout')) {
            $user = Auth::user();
            if ($user) {
                AuditLogger::log('LOGOUT', "User logged out: {$user->email}", $user, $user->name, $request);
            }
            Auth::logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            return redirect()->route('login')->with('success', 'You have been logged out.');
        }

        $currentUser = Auth::user();

        return view('auth.login', compact('currentUser'));
    }

    /**
     * Handle the login.
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->validated();

        $throttleKey = Str::transliterate(Str::lower($request->input('email')) . '|' . $request->ip());

        // Rate limit: 5 attempts per minute
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            AuditLogger::log('LOGIN_FAILED', "Login throttled for email: {$request->input('email')}. Locked for {$seconds} seconds.", null, $request->input('email'), $request);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        // If the user exists but is inactive, stop here
        $user = User::where('email', $request->input('email'))->first();

        if ($user && !$user->isActive()) {
            RateLimiter::hit($throttleKey);
            AuditLogger::log('LOGIN_DEACTIVATED_BLOCKED', "Login blocked for deactivated account: {$user->email} (Role: {$user->role})", $user, $user->name, $request);

            return back()->withInput($request->only('email', 'remember'))
                ->with('error', 'This account has been deactivated. Please contact the camp owner.');
        }

        // Try to log in
        $remember = $request->boolean('remember');

        if (Auth::attempt($credentials, $remember)) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            $authenticatedUser = Auth::user();
            
            // Use the flag to decide. Seeded accounts with a permanent password
            // may not have a login recorded yet.
            $needsPasswordChange = (bool) $authenticatedUser->must_change_password;

            if ($needsPasswordChange) {
                $authenticatedUser->update([
                    'must_change_password' => true,
                ]);
            } else {
                $authenticatedUser->update([
                    'last_login_at' => now(),
                ]);
            }

            AuditLogger::log('LOGIN_SUCCESS', "User logged in: {$authenticatedUser->email} (Role: {$authenticatedUser->role})", $authenticatedUser, $authenticatedUser->name, $request);

            if ($needsPasswordChange) {
                return redirect()->route('password.force_change')
                    ->with('warning', 'Please change your temporary password before accessing your dashboard.');
            }

            return $this->authenticatedRedirect($authenticatedUser);
        }

        // Wrong email or password
        RateLimiter::hit($throttleKey);
        AuditLogger::log('LOGIN_FAILED', "Failed login attempt for email: {$request->input('email')}", $user, $request->input('email'), $request);

        $errorMessage = $user 
            ? 'The password you entered is incorrect. Please check your password or click "Forgot password?".'
            : 'These credentials do not match our records. Please check your email address and password.';

        $fieldErrors = $user
            ? ['password' => 'Incorrect password entered.']
            : ['email' => 'These credentials do not match our records.'];

        return back()->withInput($request->only('email', 'remember'))
            ->with('error', $errorMessage)
            ->withErrors($fieldErrors);
    }

    /**
     * Log out.
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if ($user) {
            AuditLogger::log('LOGOUT', "User logged out: {$user->email}", $user, $user->name, $request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been successfully logged out.');
    }

    /**
     * Where to go after login, based on role and if the password needs changing.
     */
    protected function authenticatedRedirect(User $user): RedirectResponse
    {
        if ($user->must_change_password) {
            return redirect()->route('password.force_change')
                ->with('warning', 'Please change your temporary password before accessing your dashboard.');
        }

        return match ($user->role) {
            'owner' => redirect()->intended(route('owner.dashboard')),
            'admin' => redirect()->intended(route('admin.dashboard')),
            'coach' => redirect()->intended(route('coach.dashboard')),
            default => redirect()->route('landing'),
        };
    }
}
