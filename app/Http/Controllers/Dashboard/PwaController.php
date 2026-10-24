<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Pwa\Pwa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The dashboard as an installable app ("EgHub" on the home screen). Its service worker caches static files only:
 * orders, tables and reports must always come live from the server.
 */
class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        return response()->json(Pwa::dashboardManifest(), 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'public, max-age=86400',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function serviceWorker(): Response
    {
        return response(view('pwa.sw-dashboard')->render(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8', 'Service-Worker-Allowed' => '/', 'Cache-Control' => 'no-cache',
        ]);
    }
}
