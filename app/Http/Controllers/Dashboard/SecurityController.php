<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Auth\SecurityController as BaseSecurityController;

/** Account security of a restaurant staff member (app.<domain>/security). */
class SecurityController extends BaseSecurityController
{
    protected string $guard = 'web';

    protected string $routes = 'dash.';

    protected string $view = 'dashboard.security';
}
