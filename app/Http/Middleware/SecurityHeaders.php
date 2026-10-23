<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side hardening on every response: no framing by other sites (clickjacking), no MIME sniffing,
 * referrer limited to the origin, camera/microphone/geolocation off, and HSTS once the request is https.
 * The dashboard host (app.<domain>) alone may use the camera of its own pages: the staff QR scanners
 * (loyalty desk, POS, voucher redeem). Landing, menus and admin keep it off.
 * Headers a controller already set win (a route may legitimately allow framing of its own page).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $defaults = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => $request->getHost() === 'app.'.central_domain()
                ? 'camera=(self), microphone=(), geolocation=()'
                : 'camera=(), microphone=(), geolocation=()',
        ];
        if ($request->isSecure()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($defaults as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
