<?php

use Illuminate\Support\Facades\Auth;

if (!function_exists('dynamic_portal_prefix')) {
    /**
     * Get the portal prefix ('owner' or 'admin') for the logged in user.
     *
     * @return string
     */
    function dynamic_portal_prefix(): string
    {
        $user = Auth::user();
        if ($user && $user->role === 'owner') {
            return 'owner';
        }
        return 'admin';
    }
}

if (!function_exists('portal_route')) {
    /**
     * URL to a route in the current user's portal.
     *
     * @param  string  $name  route name without 'admin.' or 'owner.' (e.g. 'pricing.index')
     * @param  mixed   $parameters
     * @param  bool    $absolute
     * @return string
     */
    function portal_route(string $name, $parameters = [], bool $absolute = true): string
    {
        $prefix = dynamic_portal_prefix();
        $routeName = "{$prefix}.{$name}";

        if (\Illuminate\Support\Facades\Route::has($routeName)) {
            return route($routeName, $parameters, $absolute);
        }

        // Use admin if there is no owner route
        if (\Illuminate\Support\Facades\Route::has("admin.{$name}")) {
            return route("admin.{$name}", $parameters, $absolute);
        }

        return route($name, $parameters, $absolute);
    }
}
