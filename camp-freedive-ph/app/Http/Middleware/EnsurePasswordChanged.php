<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            // Allow the change password page and logout
            if (!$request->routeIs('password.force_change', 'password.force_change.update', 'logout')) {
                return redirect()->route('password.force_change')
                    ->with('warning', 'Please change your temporary password before accessing your dashboard.');
            }
        }

        return $next($request);
    }
}
