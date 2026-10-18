<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use App\Models\ServiceRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ServiceRequested;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * "Let our team enter your menu" ticked at sign-up: the paid menu_setup service (App\Support\SiteServices) is
 * requested with the new account, stored like a request from egmenu.app/services and emailed to the admins.
 * The dashboard home suggests the service while the menu is still thin and nobody with this phone asked for it.
 */
class MenuSetupRequest
{
    public const SERVICE = 'menu_setup';

    /** The menu is "still thin" under this many items. */
    public const THIN_MENU_ITEMS = 10;

    /** Creates the request when the sign-up form's checkbox was ticked; null otherwise. */
    public function fromSignup(Request $request, Tenant $tenant, User $owner): ?ServiceRequest
    {
        if (! $request->boolean('menu_setup')) {
            return null;
        }

        $serviceRequest = ServiceRequest::query()->create([
            'service' => self::SERVICE, 'name' => $owner->name, 'restaurant' => $tenant->name,
            'phone' => (string) ($owner->phone ?: $request->input('phone')), 'email' => $owner->email,
            'notes' => __('Requested at sign-up. Menu: :url', ['url' => $tenant->url()]),
            'locale' => app()->getLocale(), 'ip' => client_ip(),
        ]);
        Notification::send(PlatformAdmin::notifiable('services.manage'), new ServiceRequested($serviceRequest));

        return $serviceRequest;
    }

    /** Whether the dashboard home should point at the service: a thin menu and no request from this restaurant's phone. */
    public function shouldSuggest(int $menuItems): bool
    {
        if ($menuItems >= self::THIN_MENU_ITEMS) {
            return false;
        }
        $phone = (string) Settings::get('phone');

        return $phone === '' || ! ServiceRequest::query()->where('service', self::SERVICE)->where('phone', $phone)->exists();
    }

    /** The service page on the main domain, with this service preselected. */
    public static function url(): string
    {
        return platform_url(null, 'services?service='.self::SERVICE);
    }
}
