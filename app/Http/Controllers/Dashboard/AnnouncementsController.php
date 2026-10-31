<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The EgWeb announcements on the dashboard (dashboard.partials.announcements): each user hides one for themselves.
 * Only an announcement this restaurant is shown can be hidden; any other id is a 404.
 */
class AnnouncementsController extends Controller
{
    public function dismiss(Request $request, int $announcement): RedirectResponse
    {
        $found = Announcement::query()->whereKey($announcement)->live()->forTenant(tenant())->firstOrFail();
        $found->dismissFor($request->user());

        return back();
    }
}
