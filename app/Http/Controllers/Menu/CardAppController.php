<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Loyalty\CardState;
use App\Services\Loyalty\WalletPasses;
use App\Services\Pwa\Pwa;
use App\Services\Pwa\PwaIcons;
use App\Services\Qr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The guest's loyalty card as its own small app on the phone (/card/{token}/...): manifest and service worker
 * scoped to that one card, the live state the installed card re-reads, a picture of the card to keep, and the
 * Apple / Google Wallet passes when the platform has its wallet keys. Everything is per token and private.
 */
class CardAppController extends Controller
{
    /** Never kept by a shared cache or a search engine: the token is the guest's key. */
    public const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow'];

    public function manifest(string $token): JsonResponse
    {
        $this->customer($token);

        return response()->json(Pwa::cardManifest($token), 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
        ] + self::PRIVATE_HEADERS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function serviceWorker(string $token): Response
    {
        $this->customer($token);
        $scope = Pwa::cardScope($token);
        $js = view('pwa.sw-card', ['token' => $token, 'scope' => $scope, 'card' => route('menu.card', $token)])->render();

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8', 'Service-Worker-Allowed' => $scope,
            'Cache-Control' => 'no-cache', 'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** What the installed card re-reads when it opens or comes back to the front. */
    public function state(string $token, CardState $cards): JsonResponse
    {
        $state = $cards->for($this->customer($token)) ?? abort(404);
        unset($state['restaurant']);

        return response()->json($state + ['checked_at' => now()->toIso8601String()], 200, self::PRIVATE_HEADERS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The card as a picture (SVG with its QR) the guest can keep in their photos. */
    public function image(string $token, CardState $cards): Response
    {
        $state = $cards->for($this->customer($token)) ?? abort(404);
        $svg = view('menu.card-image', ['state' => $state, 'qr' => Qr::dataUrl($state['scan_url'], 360, '#111111')])->render();

        return response($svg, 200, ['Content-Type' => 'image/svg+xml; charset=utf-8'] + self::PRIVATE_HEADERS);
    }

    public function googleWallet(string $token, CardState $cards, WalletPasses $passes): RedirectResponse
    {
        $state = $cards->for($this->customer($token)) ?? abort(404);
        $url = $passes->googleSaveUrl($state, route('menu.icon', ['size' => 512])) ?? abort(404);

        return redirect()->away($url)->withHeaders(self::PRIVATE_HEADERS);
    }

    public function appleWallet(string $token, CardState $cards, WalletPasses $passes, PwaIcons $icons): Response
    {
        $state = $cards->for($this->customer($token)) ?? abort(404);
        $images = [];
        foreach (['icon.png' => 29, 'icon@2x.png' => 58, 'icon@3x.png' => 87, 'logo.png' => 50, 'logo@2x.png' => 100] as $file => $size) {
            $images[$file] = PwaIcons::available() ? $icons->tenant($size) : (string) file_get_contents(public_path('icons/egmenu-192.png'));
        }
        $pass = $passes->applePass($state, $images) ?? abort(404);

        return response($pass, 200, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => 'attachment; filename="card.pkpass"',
        ] + self::PRIVATE_HEADERS);
    }

    /** The card of this restaurant only: tenant-scoped query, an unknown or other restaurant's token is a 404. */
    private function customer(string $token): Customer
    {
        return Customer::query()->where('card_token', $token)->firstOrFail();
    }
}
