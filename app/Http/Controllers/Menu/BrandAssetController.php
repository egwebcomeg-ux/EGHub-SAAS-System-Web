<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Services\BrandAssets;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The restaurant's own SVG brand pieces (App\Services\BrandAssets), already sanitised when uploaded, served with a
 * CSP that allows no script, no outside request and no framing, so even opened on its own the file only draws.
 */
class BrandAssetController extends Controller
{
    public function show(string $file): Response
    {
        $path = BrandAssets::ownSvg($file);
        abort_if($path === null, 404);

        return response((string) Storage::disk('public')->get($path), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
