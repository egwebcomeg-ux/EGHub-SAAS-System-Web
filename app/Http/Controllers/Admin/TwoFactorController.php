<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\TwoFactorChallengeController;

/** Second login step of a platform admin (admin.<domain>/two-factor). */
class TwoFactorController extends TwoFactorChallengeController
{
    protected string $guard = 'admin';

    protected string $routes = 'admin.';
}
