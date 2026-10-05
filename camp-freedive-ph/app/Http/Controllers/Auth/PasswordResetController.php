<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use App\Http\Requests\Auth\SendResetLinkRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;

class PasswordResetController extends Controller
{
    /**
     * Show the forgot password form.
     */
    public function showForgotForm(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Send password reset link to user.
     */
    public function sendResetLink(SendResetLinkRequest $request): RedirectResponse
    {
        $user = User::where('email', $request->email)->first();
        $resetUrl = null;

        if ($user && $user->isActive()) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => Carbon::now(),
                ]
            );

            $resetUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);

            try {
                Mail::to($user->email)->send(new PasswordResetMail($resetUrl, $user));
            } catch (\Throwable $e) {
                Log::warning("Failed sending password reset email to {$user->email}: " . $e->getMessage());
            }

            AuditLogger::log('PASSWORD_RESET_LINK_SENT', "Password reset link requested for: {$user->email}", $user, $user->name, $request);

            Log::info("Password Reset Link for {$user->email}: {$resetUrl}");
        }

        $redirect = back()->with('status', 'If an active account exists with that email, we have sent a password reset link.');

        // In local development, provide direct link so developers/admins can easily test password reset
        if (app()->environment('local') && $resetUrl) {
            $redirect->with('dev_reset_link', $resetUrl);
        }

        return $redirect;
    }

    /**
     * Show the password reset form.
     */
    public function showResetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * Reset the user password.
     */
    public function resetPassword(ResetPasswordRequest $request): RedirectResponse
    {
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
            return back()->withErrors(['email' => 'This password reset token is invalid or has expired.']);
        }

        // Token expired after 60 minutes
        if (Carbon::parse($resetRecord->created_at)->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return back()->withErrors(['email' => 'This password reset link has expired. Please request a new one.']);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return back()->withErrors(['email' => 'Unable to find user with that email address.']);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'must_change_password' => false,
            'remember_token' => Str::random(60),
        ]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        AuditLogger::log('PASSWORD_RESET', "Password successfully reset for user: {$user->email}", $user, $user->name, $request);

        return redirect()->route('login')->with('success', 'Your password has been successfully reset! You can now log in.');
    }
}
