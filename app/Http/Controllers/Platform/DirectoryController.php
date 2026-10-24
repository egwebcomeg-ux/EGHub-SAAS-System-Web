<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Seo\Directory;
use App\Support\Cities;
use App\Support\Cuisines;
use App\Support\PlatformBrand;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Restaurant directory: /restaurants, /restaurants/{city}, /restaurants/{city}/{cuisine} on the main domain, or on the earlier one after a move (its home page, routes/web.php). */
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
        // featured places (paid add-on, max FEATURED_PER_PAGE) first, then everyone by name
        $featured = Directory::featuredIds($city, $cuisine, $search);
        $places = Directory::query($city, $cuisine, $search)
            ->when($featured !== [], fn ($q) => $q->orderByRaw(
                'case id '.implode(' ', array_fill(0, count($featured), 'when ? then ?')).' else ? end',
                [...array_merge(...array_map(fn ($id, $i) => [$id, $i], $featured, array_keys($featured))), count($featured)]
            ))
            ->orderBy('name')->paginate(Directory::PER_PAGE)->withQueryString();

        // after the move to eghub.app the directory keeps the earlier domain and its name (routes/web.php)
        $ownDomain = directory_domain() !== central_domain();
        $cityName = Cities::name($city);
        $cuisineName = $cuisine ? Cuisines::name($cuisine) : '';
        $heading = match (true) {
            $cuisine !== null => __(':cuisine in :city', ['cuisine' => $cuisineName, 'city' => $cityName]),
            $city !== null => __('Restaurants and cafes in :city', ['city' => $cityName]),
            $ownDomain => __('Restaurants and cafes in Egypt'),
            default => __('Restaurants and cafes on EgHub'),
        };

        return view('landing.directory', [
            'places' => $places,
            'cards' => Directory::cards($places->items(), $featured),
            'city' => $city,
            'cuisine' => $cuisine,
            'search' => $search,
            'heading' => $heading,
            'directoryName' => $ownDomain ? __('EgMenu directory') : PlatformBrand::siteName(),
            'description' => $city !== null
                ? __('Menus with prices of :what: addresses, opening hours, ratings and ordering from the table or delivery.', ['what' => $heading])
                : __('Find cafes and restaurants in Egypt that use EgHub: menus with prices, addresses, opening hours and ratings.'),
            'cities' => Directory::cityCounts(),
            'cuisines' => $city !== null ? Directory::cuisineCounts($city) : [],
        ]);
    }
}
