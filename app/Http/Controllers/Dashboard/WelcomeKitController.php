<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Entitlements;
use App\Services\Qr;
use App\Services\Settings;
use App\Services\WelcomeKit;
use Illuminate\Http\Response;

/**
 * The owner's printable welcome page (app.<domain>/welcome-kit/{place}/{nonce}, signed, 14 days): no sign-in, so
 * the signature and the nonce of the current kit are the only keys; a new kit makes older links fail. Everything
 * on it is read inside that restaurant only. Never indexed or cached, no referrer leaves it.
 */
class WelcomeKitController extends Controller
{
    public function show(string $place, string $nonce, WelcomeKit $kit): Response
    {
        $tenant = Tenant::query()->findOrFail($place);
        $state = $kit->state($tenant);
        abort_unless($state && hash_equals($state['nonce'], $nonce), 403);
        $owner = $kit->owner($tenant);
        abort_unless($owner && $owner->is_active, 403);
        // in the owner's language (Arabic unless they picked English); a ?lang= switch would break the signature
        app()->setLocale($owner->preferredLocale());

        tenancy()->initialize($tenant);
        try {
            $settings = Settings::all();
            $sub = $tenant->subscription()->with('plan')->first();
            $rights = Entitlements::for($tenant);
            $menuUrl = $tenant->url();
            $html = view('welcome-kit', [
                'tenant' => $tenant,
                'brand' => tr($settings['brand_name'], $settings['brand_name_en']) ?: $tenant->name,
                'logo' => trim($settings['logo']) !== '' ? '/storage/'.ltrim($settings['logo'], '/') : null,
                'menuUrl' => $menuUrl,
                'qr' => Qr::svg($menuUrl, 220, '#0A2540'),
                'loginUrl' => platform_url('app', '/login'),
                'owner' => $owner,
                'placeholderEmail' => WelcomeKit::isPlaceholder($owner->email, $tenant),
                'password' => $owner->must_change_password ? $owner->temporary_password : null,
                'sub' => $sub,
                'rights' => $rights,
                'expiresAt' => $kit->expiresAt($tenant),
                'support' => WelcomeKit::support(),
            ])->render();
        } finally {
            tenancy()->end();
            Settings::flush();
        }

        return response($html)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
