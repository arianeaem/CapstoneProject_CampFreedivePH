<?php

use Illuminate\Support\Facades\Auth;

if (!function_exists('dynamic_portal_prefix')) {
    /**
     * Get the dynamic portal prefix ('owner' or 'admin') based on the authenticated user.
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
     * Generate a URL to a named route in the current user's portal namespace.
     *
     * @param  string  $name  Route name without the 'admin.' or 'owner.' prefix (e.g. 'pricing.index')
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

        // Fallback to admin if owner route doesn't exist
        if (\Illuminate\Support\Facades\Route::has("admin.{$name}")) {
            return route("admin.{$name}", $parameters, $absolute);
        }

        return route($name, $parameters, $absolute);
    }
}
