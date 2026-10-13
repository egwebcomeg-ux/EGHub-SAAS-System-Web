<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\PasswordResetController as BaseController;

/** Platform admins (admin.<domain>). */
class PasswordResetController extends BaseController
{
    protected function broker(): string
    {
        return 'platform_admins';
    }

    protected function prefix(): string
    {
        return 'admin';
    }
}
