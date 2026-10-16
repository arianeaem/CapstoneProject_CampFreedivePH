<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out other sessions after a password change (reset, forced change, admin-assigned).
 *
 * Each session keeps a fingerprint of the password it logged in with. If the same account's
 * password has changed since, the session is ended. The session that made the change gets
 * the new fingerprint at the end of that request, so it stays logged in.
 *
 * Laravel's AuthenticateSession skips the login request itself, so a password changed before
 * the session's next request would be accepted; this one records the fingerprint on login too.
 */
class EnsureSessionPasswordIsCurrent
{
    private const KEY = 'auth_password_fingerprint';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && ($user = $request->user())) {
            $stored = $request->session()->get(self::KEY);

            // Only compare when this session recorded the same account; a different account
            // in the session can only come from a new login, which records a fresh fingerprint.
            if (is_array($stored)
                && ($stored['user_id'] ?? null) === $user->getAuthIdentifier()
                && !hash_equals((string) ($stored['hash'] ?? ''), $this->fingerprint($user->getAuthPassword()))) {
                Auth::guard()->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw new AuthenticationException('Your password was changed. Please log in again.', [], route('login'));
            }
        }

        $response = $next($request);

        if ($request->hasSession() && ($current = Auth::user())) {
            $request->session()->put(self::KEY, [
                'user_id' => $current->getAuthIdentifier(),
                'hash' => $this->fingerprint($current->getAuthPassword()),
            ]);
        }

        return $response;
    }

    /** Keyed hash, so the session never holds the password hash itself. */
    private function fingerprint(string $passwordHash): string
    {
        return hash_hmac('sha256', $passwordHash, (string) config('app.key'));
    }
}
