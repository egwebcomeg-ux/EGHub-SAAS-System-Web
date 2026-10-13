<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Auth\PasswordResetController as BaseController;

/** Restaurant staff: reset by email (staff who sign in with a mobile number ask their owner instead). */
class PasswordResetController extends BaseController
{
    protected function broker(): string
    {
        return 'users';
    }

    protected function prefix(): string
    {
        return 'dash';
    }
}
