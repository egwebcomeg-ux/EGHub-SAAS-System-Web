<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\SecurityController as BaseSecurityController;

/** Account security of a platform admin (admin.<domain>/security). */
class SecurityController extends BaseSecurityController
{
    protected string $guard = 'admin';

    protected string $routes = 'admin.';

    protected string $view = 'admin.security';
}
