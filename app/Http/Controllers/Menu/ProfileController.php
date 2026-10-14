<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Services\Seo\RestaurantProfile;
use Illuminate\View\View;

/** The restaurant's page for search engines and link previews: egmenu.app/{slug}/about (works on every host). */
class ProfileController extends Controller
{
    public function show(): View
    {
        $profile = new RestaurantProfile;
        $branches = $profile->branches();
        $categories = RestaurantProfile::catalog($branches->first());

        return view('menu.about', [
            'profile' => $profile,
            'settings' => $profile->settings,
            'branches' => $branches,
            'featured' => $profile->featuredItems($categories),
            'itemsCount' => $categories->sum(fn ($c) => $c->items->count()),
            'rating' => $profile->rating(),
            'ordering' => $branches->isNotEmpty() ? $profile->ordering($branches->first()) : ['table' => false, 'takeaway' => false, 'delivery' => false],
            'schemas' => [$profile->placeSchema($branches), $profile->menuSchema($categories)],
        ]);
    }
}
