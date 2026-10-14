<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Seo\Directory;
use App\Support\Cities;
use App\Support\Cuisines;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Restaurant directory on the main domain: /restaurants, /restaurants/{city}, /restaurants/{city}/{cuisine}. */
class DirectoryController extends Controller
{
    public function index(Request $request): View
    {
        return $this->page($request, null, null);
    }

    public function city(Request $request, string $city): View
    {
        abort_unless(Cities::exists($city), 404);

        return $this->page($request, $city, null);
    }

    public function cuisine(Request $request, string $city, string $cuisine): View
    {
        abort_unless(Cities::exists($city) && Cuisines::exists($cuisine), 404);

        return $this->page($request, $city, $cuisine);
    }

    private function page(Request $request, ?string $city, ?string $cuisine): View
    {
        $search = $city === null ? mb_substr(trim((string) $request->query('q', '')), 0, 60) : '';
        if ($search !== '') {
            $request->attributes->set('noindex', true); // search results are not pages of their own
        }
        $places = Directory::query($city, $cuisine, $search)->orderBy('name')->paginate(Directory::PER_PAGE)->withQueryString();

        $cityName = Cities::name($city);
        $cuisineName = $cuisine ? Cuisines::name($cuisine) : '';
        $heading = match (true) {
            $cuisine !== null => __(':cuisine in :city', ['cuisine' => $cuisineName, 'city' => $cityName]),
            $city !== null => __('Restaurants and cafes in :city', ['city' => $cityName]),
            default => __('Restaurants and cafes on EgMenu'),
        };

        return view('landing.directory', [
            'places' => $places,
            'cards' => Directory::cards($places->items()),
            'city' => $city,
            'cuisine' => $cuisine,
            'search' => $search,
            'heading' => $heading,
            'description' => $city !== null
                ? __('Menus with prices of :what: addresses, opening hours, ratings and ordering from the table or delivery.', ['what' => $heading])
                : __('Find cafes and restaurants in Egypt that use EgMenu: menus with prices, addresses, opening hours and ratings.'),
            'cities' => Directory::cityCounts(),
            'cuisines' => $city !== null ? Directory::cuisineCounts($city) : [],
        ]);
    }
}
