<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Notifications\DomainRequested;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The restaurant's own domain. Two ways in: book a new domain on egweb.com.eg (EgWeb registers it and links it),
 * or bring a domain it already has and point it at our server. EgWeb activates it from the admin panel
 * (server alias + SSL); from then on the menu and every QR code use it.
 */
class DomainController extends Controller
{
    public const DEFAULT_CART_URL = 'https://egweb.com.eg/cart.php?a=add&domain=register&query=';

    public function show(): View
    {
        return view('dashboard.domain', [
            'domain' => tenant()->domains()->latest('id')->first(),
            'allowed' => Entitlements::current()->allows('custom_domain'),
            'serverIp' => PlatformSetting::get('server_ip', ''),
        ]);
    }

    /** "Book it through EgWeb": records the request and sends the owner to the egweb.com.eg cart with the name filled in. */
    public function book(Request $request): RedirectResponse
    {
        $host = $this->validated($request);
        $this->replace($host, 'egweb', 'requested');
        $cart = PlatformSetting::get('domain_cart_url') ?: self::DEFAULT_CART_URL;

        return redirect()->away($cart.urlencode($host));
    }

    /** "I already have a domain": EgWeb connects it once its DNS points to our server. */
    public function connect(Request $request): RedirectResponse
    {
        $this->replace($this->validated($request), 'own', 'pending');

        return back()->with('ok', __('Request sent. Point the domain to our server as shown below, and we will connect it.'));
    }

    /** Checks whether the domain already resolves to our server (a hint for the owner; EgWeb still activates it). */
    public function check(): RedirectResponse
    {
        $domain = tenant()->domains()->latest('id')->firstOrFail();
        $ip = PlatformSetting::get('server_ip', '');
        $resolved = gethostbyname($domain->domain);

        return back()->with('ok', $ip !== '' && $resolved === $ip
            ? __('The domain points to our server. We will finish connecting it shortly.')
            : __('The domain does not point to our server yet (it points to :ip). DNS changes can take a few hours.', ['ip' => $resolved === $domain->domain ? '—' : $resolved]));
    }

    public function destroy(): RedirectResponse
    {
        tenant()->domains()->delete();

        return back()->with('ok', __('Domain removed. Your menu uses the EgMenu link again.'));
    }

    private function validated(Request $request): string
    {
        abort_unless(Entitlements::current()->allows('custom_domain'), 403);
        $request->validate(['domain' => 'required|string|max:255']);
        $host = Domain::normalize((string) $request->input('domain'));
        if (! $host) {
            throw ValidationException::withMessages(['domain' => __('Write a domain name like mycafe.com')]);
        }
        $taken = Domain::query()->where('domain', $host)->where('tenant_id', '!=', tenant()->getTenantKey())->exists();
        if ($taken) {
            throw ValidationException::withMessages(['domain' => __('This domain is already linked to another restaurant.')]);
        }

        return $host;
    }

    /** One domain per restaurant: a new request replaces the previous one. */
    private function replace(string $host, string $source, string $status): void
    {
        $tenant = tenant();
        $tenant->domains()->where('domain', '!=', $host)->delete();
        $domain = Domain::query()->updateOrCreate(['domain' => $host], [
            'tenant_id' => $tenant->getTenantKey(), 'source' => $source, 'status' => $status, 'verified_at' => null,
        ]);
        Notification::send(PlatformAdmin::query()->get(), new DomainRequested($domain));
    }
}
