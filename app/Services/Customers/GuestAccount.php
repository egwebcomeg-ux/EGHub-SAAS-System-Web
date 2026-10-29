<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Services\Customers\SignIn\GuestIdentity;
use App\Services\Settings;
use App\Support\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * "حسابي": the guest's own page at one restaurant (/me/{token}). The key is the customer's card_token, the same
 * secret as the loyalty card link, and the phone remembers it in the card's per-restaurant cookie. Everything is
 * looked up inside the current restaurant only (Customer and Order carry the tenant scope), so another
 * restaurant's token is simply not found. Rotating the token (dashboard "رابط جديد") ends every old link and every
 * phone that remembered it, the card link included.
 */
final class GuestAccount
{
    public const TOKEN_PATTERN = '[a-f0-9]{24}';

    public const MAX_ADDRESSES = 5;

    public const ORDERS_SHOWN = 30;

    /** The cookie this phone keeps the guest in, per restaurant (shared with the loyalty card and the wallet). */
    public static function cookieName(): string
    {
        return 'card_'.tenant()->getTenantKey();
    }

    /** The guest this phone remembers at this restaurant, if the remembered key is still theirs. */
    public static function fromRequest(Request $request): ?Customer
    {
        return self::byToken($request->cookie(self::cookieName()));
    }

    public static function byToken(mixed $token): ?Customer
    {
        if (! is_string($token) || preg_match('/^'.self::TOKEN_PATTERN.'$/', $token) !== 1) {
            return null;
        }

        return Customer::query()->where('card_token', $token)->first();
    }

    /** The guest's key, created for an older guest that never had one. */
    public static function token(Customer $customer): string
    {
        if (! is_string($customer->card_token) || preg_match('/^'.self::TOKEN_PATTERN.'$/', $customer->card_token) !== 1) {
            $customer->forceFill(['card_token' => random_token(12)])->save();
        }

        return $customer->card_token;
    }

    public const SCAN_PATTERN = '[a-f0-9]{20}';

    /**
     * The code the card's QR carries: the counter finds the guest by it, and it opens nothing (no account, no balance),
     * unlike the card link, which is the guest's key. Made the first time it is needed.
     */
    public static function scanCode(Customer $customer): string
    {
        if (! is_string($customer->scan_code) || preg_match('/^'.self::SCAN_PATTERN.'$/', $customer->scan_code) !== 1) {
            do {
                $code = random_token(10);
            } while (Customer::query()->where('scan_code', $code)->exists());
            $customer->forceFill(['scan_code' => $code])->save();
        }

        return $customer->scan_code;
    }

    /** What the card's QR opens: a page with the code for the cashier, nothing of the guest's. */
    public static function scanUrl(Customer $customer): string
    {
        return route('menu.card.scan', self::scanCode($customer));
    }

    /** The guest a scanned card or a typed code points at: the card's scan code, or (older cards) the card link. */
    public static function fromScan(string $scanned): ?Customer
    {
        $scanned = trim($scanned);
        // typed from the card page: upper case, in groups of four
        $typed = strtolower((string) preg_replace('/\s+/', '', $scanned));
        if (preg_match('/^'.self::SCAN_PATTERN.'$/', $typed) === 1) {
            return Customer::query()->where('scan_code', $typed)->first();
        }
        if (preg_match('#(?:^|/)('.self::SCAN_PATTERN.')$#', $scanned, $m) === 1) {
            return Customer::query()->where('scan_code', $m[1])->first();
        }

        return preg_match('#('.self::TOKEN_PATTERN.')$#', $scanned, $m) === 1 ? self::byToken($m[1]) : null;
    }

    /** The account link on the address the guest is using (menu pages). */
    public static function url(Customer $customer): string
    {
        return route('menu.account', self::token($customer));
    }

    /** The account link on the restaurant's canonical address (dashboard, messages). */
    public static function publicUrl(Customer $customer): string
    {
        return tenant()->url('me/'.self::token($customer));
    }

    /** A new key: old account links, card links and every phone that remembered the old one stop working. */
    public static function rotate(Customer $customer): string
    {
        do {
            $token = random_token(12);
        } while (Customer::query()->withoutGlobalScopes()->where('card_token', $token)->exists());
        // a new card QR too: the old one may have been photographed
        $customer->forceFill(['card_token' => $token, 'scan_code' => null])->save();

        return $token;
    }

    /**
     * A guest who came in with Google or an email + password and had no mobile ($google) just proved a mobile
     * ($proven: WhatsApp, a previous order number, a link to that guest's email) in the same browser: the way in moves
     * onto the proven guest and the empty row is removed (GuestIdentity::adopt). Both rows belong to the current
     * restaurant (tenant scope); a proven guest already linked to a Google account is never relinked.
     */
    public static function adoptGoogleGuest(?Customer $google, Customer $proven): void
    {
        GuestIdentity::adopt($google, $proven);
    }

    /** Long-lived, HttpOnly, SameSite=Lax, Secure on https / in production. */
    public static function rememberCookie(string $token): Cookie
    {
        return cookie()->forever(self::cookieName(), $token, null, null, self::secure(), true, false, 'lax');
    }

    /**
     * This browser proved the guest's mobile (a code sent to it, or a message from it): kept apart from the card cookie,
     * which a typed mobile on a first order or the card sign-up also hands out. Tied to the guest and the mobile, so it
     * stops counting when either changes.
     */
    public static function phoneProofCookie(Customer $customer): Cookie
    {
        return cookie()->forever('phone_ok_'.tenant()->getTenantKey(), self::phoneProof($customer), null, null, self::secure(), true, false, 'lax');
    }

    /** The guest this phone remembers proved this mobile from this browser. */
    public static function provedPhone(Request $request, Customer $customer): bool
    {
        $proof = $request->cookie('phone_ok_'.tenant()->getTenantKey());

        return $customer->phone !== '' && is_string($proof) && hash_equals(self::phoneProof($customer), $proof);
    }

    private static function phoneProof(Customer $customer): string
    {
        return hash_hmac('sha256', tenant()->getTenantKey().'|'.$customer->id.'|'.$customer->phone, (string) config('app.key'));
    }

    public static function forgetCookie(): Cookie
    {
        return cookie(self::cookieName(), '', -2628000, null, null, self::secure(), true, false, 'lax');
    }

    private static function secure(): bool
    {
        return (bool) (config('session.secure') ?? (request()->isSecure() || app()->isProduction()));
    }

    /** 01012345678 → 010•••••678 */
    public static function maskedPhone(?string $phone): string
    {
        $phone = (string) $phone;
        if (strlen($phone) < 7) {
            return str_repeat('•', strlen($phone));
        }

        return substr($phone, 0, 3).str_repeat('•', strlen($phone) - 6).substr($phone, -3);
    }

    /**
     * The guest's last orders, newest first, with what they need on the account page.
     *
     * @return Collection<int, Order>
     */
    public static function orders(Customer $customer): Collection
    {
        return Order::query()->where('customer_id', $customer->id)->with('items')
            ->latest('id')->limit(self::ORDERS_SHOWN)->get();
    }

    /** Inside the place the bill total may be hidden from guests (setting dine_in_hide_total). */
    public static function showsTotal(Order $order): bool
    {
        return ! ($order->type === 'dine_in' && Settings::get('dine_in_hide_total') === '1');
    }

    /** "2× Latte، 1× Cake" */
    public static function itemsSummary(Order $order, int $max = 4): string
    {
        $glue = app()->getLocale() === 'ar' ? '، ' : ', ';
        $lines = $order->items->map(fn ($line) => $line->quantity.'× '.$line->name);
        $more = $lines->count() - $max;

        return $lines->take($max)->implode($glue).($more > 0 ? $glue.'+'.$more : '');
    }

    /**
     * The guest's saved delivery addresses (the same list the call center uses), most recent first. The first time
     * it is read, a guest who never had a list gets one from their past delivery orders.
     *
     * @return list<array{key:string, label:string, zone_id:?int, zone:string, text:string, lat:?float, lng:?float, parts:array<string,string>}>
     */
    public static function addresses(Customer $customer): array
    {
        self::backfillAddresses($customer);
        $saved = array_values(array_filter((array) $customer->addresses, 'is_array'));
        $zones = DeliveryZone::query()->with('parent')->whereIn('id', array_filter(array_column($saved, 'zone_id')))->get()->keyBy('id');

        return array_map(fn (array $a) => [
            'key' => self::addressKey($a),
            'label' => (string) ($a['label'] ?? ''),
            'zone_id' => isset($a['zone_id']) ? (int) $a['zone_id'] : null,
            'zone' => ($zone = $zones->get((int) ($a['zone_id'] ?? 0))) ? $zone->label() : (string) ($a['zone'] ?? ''),
            'text' => (string) ($a['text'] ?? ''),
            'lat' => isset($a['lat']) ? (float) $a['lat'] : null,
            'lng' => isset($a['lng']) ? (float) $a['lng'] : null,
            'parts' => DeliveryAddress::clean(is_array($a['parts'] ?? null) ? $a['parts'] : null),
        ], $saved);
    }

    /** Removes one saved address of THIS guest; false when the key is not one of theirs. */
    public static function forgetAddress(Customer $customer, string $key): bool
    {
        self::backfillAddresses($customer);
        $saved = collect((array) $customer->addresses)->filter(fn ($a) => is_array($a));
        $kept = $saved->reject(fn (array $a) => hash_equals(self::addressKey($a), $key));
        if ($kept->count() === $saved->count()) {
            return false;
        }
        $customer->addresses = $kept->values()->all();
        $customer->save();

        return true;
    }

    /**
     * An online delivery order keeps its address on the guest (most recent first, no duplicates), with the pin
     * confirmed on the map. An order priced by distance has no zone: its pin and its label ("2.3 km …") are kept.
     */
    public static function rememberAddress(Order $order): void
    {
        $customer = $order->customer_id ? Customer::query()->find($order->customer_id) : null;
        $text = trim((string) ($order->delivery_address['text'] ?? ''));
        $pinned = isset($order->delivery_address['lat'], $order->delivery_address['lng']);
        if (! $customer || $order->type !== 'delivery' || (! $order->delivery_zone_id && ! $pinned) || $text === '') {
            return;
        }
        self::backfillAddresses($customer);
        // the address typed in parts keeps them, and the label chosen with it (البيت / الشغل)
        $parts = DeliveryAddress::clean(is_array($order->delivery_address['parts'] ?? null) ? $order->delivery_address['parts'] : null);
        $entry = ['label' => DeliveryAddress::labelName($parts['label'] ?? null), 'zone_id' => $order->delivery_zone_id ? (int) $order->delivery_zone_id : null, 'text' => $text]
            + ($order->delivery_zone_id ? [] : ['zone' => (string) ($order->delivery_address['zone'] ?? '')])
            + ($pinned ? ['lat' => (float) $order->delivery_address['lat'], 'lng' => (float) $order->delivery_address['lng']] : [])
            + ($parts !== [] ? ['parts' => $parts] : []);
        $customer->addresses = collect((array) $customer->addresses)->filter(fn ($a) => is_array($a))
            ->reject(fn (array $a) => self::addressKey($a) === self::addressKey($entry))
            ->prepend($entry)->take(self::MAX_ADDRESSES)->values()->all();
        $customer->save();
    }

    /** A guest whose list was never written gets the addresses of their past delivery orders, once. */
    private static function backfillAddresses(Customer $customer): void
    {
        if ($customer->getRawOriginal('addresses') !== null || $customer->isDirty('addresses')) {
            return;
        }
        $list = [];
        $orders = Order::query()->where('customer_id', $customer->id)->where('type', 'delivery')->whereNotNull('delivery_zone_id')
            ->latest('id')->limit(50)->get(['id', 'delivery_zone_id', 'delivery_address']);
        foreach ($orders as $order) {
            $text = trim((string) ($order->delivery_address['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $entry = ['label' => '', 'zone_id' => (int) $order->delivery_zone_id, 'text' => $text]
                + (isset($order->delivery_address['lat'], $order->delivery_address['lng'])
                    ? ['lat' => (float) $order->delivery_address['lat'], 'lng' => (float) $order->delivery_address['lng']] : []);
            $list[self::addressKey($entry)] ??= $entry;
            if (count($list) >= self::MAX_ADDRESSES) {
                break;
            }
        }
        $customer->addresses = array_values($list);
        $customer->save();
    }

    /** A short stable id of an address (zone + text) for the delete button. */
    public static function addressKey(array $address): string
    {
        return substr(hash('sha256', ((int) ($address['zone_id'] ?? 0)).'|'.trim((string) ($address['text'] ?? ''))), 0, 16);
    }

    /**
     * The "your account" card after an online order (tracking page, pager page). The link itself is shown only to
     * the phone that already holds this guest's key (set when the order created the guest, or by their link):
     * typing someone's mobile into an order must never hand out their account.
     *
     * @return array{brand:string, url:?string, whatsapp:?string, phone:string, number:int, served:bool}|null
     */
    public static function orderCard(Order $order, Request $request): ?array
    {
        if (! in_array($order->type, ['takeaway', 'delivery'], true) || ! $order->customer_id) {
            return null;
        }
        $customer = Customer::query()->find($order->customer_id);
        if (! $customer) {
            return null;
        }
        $settings = Settings::all();
        $brand = trim(tr($settings['brand_name'], $settings['brand_name_en'])) ?: (string) tenant()->name;
        $mine = ($remembered = self::fromRequest($request)) !== null && $remembered->is($customer);
        $url = $mine ? self::url($customer) : null;

        return [
            'brand' => $brand,
            'url' => $url,
            'whatsapp' => $url ? 'https://wa.me/'.whatsapp_number($customer->phone).'?text='.rawurlencode(
                __('My account at :place (orders, addresses, points): :url', ['place' => $brand, 'url' => $url])) : null,
            'phone' => self::maskedPhone($customer->phone),
            'number' => (int) $order->number,
            'served' => $order->status === 'served',
        ];
    }
}
