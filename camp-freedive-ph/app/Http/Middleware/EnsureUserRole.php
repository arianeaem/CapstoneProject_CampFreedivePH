<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        if (!in_array($user->role, $roles)) {
            // Owner opened an /admin/* page, send them to the owner version
            if ($user->role === 'owner' && ($request->is('admin') || $request->is('admin/*'))) {
                $path = $request->path();
                $newPath = preg_replace('#^admin#', 'owner', $path);
                $qs = $request->getQueryString();
                return redirect('/' . $newPath . ($qs ? '?' . $qs : ''), 307);
            }

            // Admin opened an /owner/* page (except audit logs), send them to the admin version
            if ($user->role === 'admin' && ($request->is('owner') || $request->is('owner/*'))) {
                if ($request->is('owner/audit-logs*') || $request->is('owner/settings/audit-logs*')) {
                    abort(403, 'Unauthorized access. You do not have permission to view this section.');
                }
                $path = $request->path();
                $newPath = preg_replace('#^owner#', 'admin', $path);
                $qs = $request->getQueryString();
                return redirect('/' . $newPath . ($qs ? '?' . $qs : ''), 307);
            }

            abort(403, 'Unauthorized access. You do not have permission to view this section.');
        }

        return $next($request);
    }
}
