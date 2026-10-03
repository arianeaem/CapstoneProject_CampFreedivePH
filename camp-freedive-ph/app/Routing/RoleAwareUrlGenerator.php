<?php

namespace App\Routing;

use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Auth;

class RoleAwareUrlGenerator extends UrlGenerator
{
    /**
     * Generate a url for a named route, automatically mapping between
     * admin and owner route namespaces based on the active user role.
     *
     * @param  string  $name
     * @param  mixed  $parameters
     * @param  bool  $absolute
     * @return string
     */
    public function route($name, $parameters = [], $absolute = true)
    {
        $user = Auth::user();

        if ($user && $user->role === 'owner') {
            if (str_starts_with($name, 'admin.')) {
                $ownerRouteName = 'owner.' . substr($name, 6);
                if ($this->routes->getByName($ownerRouteName)) {
                    $name = $ownerRouteName;
                }
            }
        } elseif ($user && $user->role === 'admin') {
            if (str_starts_with($name, 'owner.')) {
                $adminRouteName = 'admin.' . substr($name, 6);
                if ($this->routes->getByName($adminRouteName)) {
                    $name = $adminRouteName;
                }
            }
        }

        return parent::route($name, $parameters, $absolute);
    }
}
