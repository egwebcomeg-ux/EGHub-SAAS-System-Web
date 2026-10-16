<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Auth\TwoFactorChallengeController;

/** Second login step of restaurant staff (app.<domain>/two-factor). */
class TwoFactorController extends TwoFactorChallengeController
{
    protected string $guard = 'web';

    protected string $routes = 'dash.';
}
