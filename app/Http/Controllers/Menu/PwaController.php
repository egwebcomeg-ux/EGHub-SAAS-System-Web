<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Services\Pwa\Pwa;
use App\Services\Pwa\PwaIcons;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The restaurant's menu as an installable app: manifest, icons made from its logo, a small service worker scoped
 * to the menu (shell cached, HTML always from the network first) and the page shown when there is no connection.
 */
class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        return response()->json(Pwa::tenantManifest(), 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function serviceWorker(): Response
    {
        $scope = Pwa::scope();
        $js = view('pwa.sw-menu', [
            'key' => tenant()->getTenantKey(), 'scope' => $scope, 'offline' => route('menu.offline'),
        ])->render();

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8', 'Service-Worker-Allowed' => $scope, 'Cache-Control' => 'no-cache',
        ]);
    }

    public function offline(): View
    {
        return view('pwa.offline', ['settings' => Settings::all()]);
    }

    /** 192 or 512 px PNG. Falls back to the EgMenu icon on a server without GD. */
    public function icon(Request $request, PwaIcons $icons, string $size): Response
    {
        $size = (int) $size;
        abort_unless(in_array($size, PwaIcons::SIZES, true), 404);
        if (! PwaIcons::available()) {
            return response(file_get_contents(public_path("icons/egmenu-$size.png")), 200, ['Content-Type' => 'image/png']);
        }
        $etag = '"'.md5($icons->fingerprint().$size).'"';
        $headers = ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400', 'ETag' => $etag];
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response($icons->tenant($size), 200, $headers);
    }
}
