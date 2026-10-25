<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\EgHubApps;
use App\Support\SiteModules;
use Illuminate\View\View;

/** Marketing site: the EgHub apps (/apps), each with what it does and whether it can be downloaded yet. */
class AppsController extends Controller
{
    public function index(): View
    {
        return view('landing.apps', [
            'apps' => EgHubApps::all(),
            'modules' => SiteModules::all(),
        ]);
    }
}
