<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\KnowledgeBase;
use App\Services\Reservations\ReservationService;
use App\Services\Seo\RestaurantProfile;
use Illuminate\View\View;

/**
 * The place's one page, egmenu.app/{slug}/about (every host): the links a guest needs as big buttons on top (the menu,
 * ordering online, booking a table, WhatsApp, social accounts), then the place itself for guests, search engines and
 * link previews (logo, description, what they serve, rating, from the menu, branches with address, hours, phones and
 * the map). /links (what a QR opens with setting qr_target "links") shows this same page; its canonical is /about.
 */
class ProfileController extends Controller
{
    public function show(): View
    {
        $profile = new RestaurantProfile;
        $settings = $profile->settings;
        $branches = $profile->branches();
        $main = $branches->first();
        $categories = RestaurantProfile::catalog($main);
        $faq = KnowledgeBase::publicArticles();

        return view('menu.about', [
            'profile' => $profile,
            'settings' => $settings,
            'branches' => $branches,
            'featured' => $profile->featuredItems($categories),
            'itemsCount' => $categories->sum(fn ($c) => $c->items->count()),
            'rating' => $profile->rating(),
            'ordering' => $main ? $profile->ordering($main) : ['table' => false, 'takeaway' => false, 'delivery' => false],
            'schemas' => array_merge([$profile->placeSchema($branches), $profile->menuSchema($categories)], $faq ? [KnowledgeBase::faqSchema($faq)] : []),
            // the knowledge base's public answers, as frequently asked questions
            'faq' => $faq,
            // the links block on top
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
