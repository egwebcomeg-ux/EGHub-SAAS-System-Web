<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Entitlements;
use App\Services\Reservations\ReservationService;
use App\Services\Seo\RestaurantProfile;
use App\Support\MenuThemes;
use Illuminate\View\View;

/**
 * The restaurant's links page egmenu.app/{slug}/links (every host): one screen with the buttons a guest needs —
 * the menu, ordering online, booking a table, WhatsApp, social accounts, the branches on the map and their hours.
 * The table and storefront QR can point here instead of the menu (setting qr_target).
 */
class LinksController extends Controller
{
    public function show(): View
    {
        $profile = new RestaurantProfile;
        $settings = $profile->settings;
        $branches = $profile->branches();
        $main = $branches->first();
        $theme = Entitlements::current()->allows('menu_themes') && in_array($settings['menu_theme'], MenuThemes::keys(), true)
            ? $settings['menu_theme'] : 'classic';

        return view('menu.links', [
            'profile' => $profile,
            'settings' => $settings,
            'branches' => $branches,
            'theme' => $theme,
            'online' => $main !== null && MenuController::onlineOptions($main, $settings) !== null,
            'reservations' => ReservationService::enabled(),
            'whatsapp' => $main ? $profile->whatsapp($main) : (string) $settings['whatsapp'],
            'maps' => $branches->mapWithKeys(fn (Branch $b) => [$b->id => $this->mapUrl($profile, $b)]),
        ]);
    }

    /** The branch's own map link, its coordinates, else a Google Maps search for its address. */
    private function mapUrl(RestaurantProfile $profile, Branch $branch): ?string
    {
        if ($url = $profile->mapUrl($branch)) {
            return $url;
        }
        $address = trim(implode(' ', array_filter([$branch->address, $profile->place()])));

        return $address !== '' ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address) : null;
    }
}
