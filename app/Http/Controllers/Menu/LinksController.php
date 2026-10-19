<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * egmenu.app/{slug}/links (every host): the old links page, now part of the place's page (/about) with its links on
 * top. Printed QR codes and shared links keep working: this address shows the same page, and its canonical is /about
 * (App\Http\Middleware\IdentifyTenantBySubdomain::markCanonical), so search engines see one page.
 */
class LinksController extends Controller
{
    public function show(ProfileController $profile): View
    {
        return $profile->show();
    }
}
