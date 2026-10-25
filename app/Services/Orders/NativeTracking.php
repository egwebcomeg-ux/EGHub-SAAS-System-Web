<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Background location from the EgHub driver app: when the driver leaves with an order the page hands the app
 * (window.EgHubNative.startTracking) a signed per-delivery endpoint and a short-lived bearer token, and the app's
 * own service keeps posting the phone's fixes there even with the screen off. The token is an HMAC (APP_KEY) of the
 * restaurant, the order, the driver and its expiry; nothing is stored. The endpoint refuses it (410: stop) as soon as
 * the order is no longer out for delivery with that driver, so a delivered, failed or cancelled order stops the app.
 */
final class NativeTracking
{
    /** How long one hand-over may report (a longer trip gets a fresh token when the driver screen is opened again). */
    public const MAX_MINUTES = 240;

    /**
     * @return array{endpoint:string, token:string, intervalSec:int, maxMinutes:int}
     */
    public static function issue(Order $order, User $driver): array
    {
        $expires = now()->addMinutes(self::MAX_MINUTES);
        $payload = self::base64url((string) json_encode([
            't' => (string) $order->tenant_id, 'o' => $order->id, 'u' => $driver->id, 'e' => $expires->getTimestamp(),
        ]));

        return [
            'endpoint' => URL::temporarySignedRoute('dash.native.track', $expires, ['order' => $order->id]),
            'token' => $payload.'.'.self::sign($payload),
            'intervalSec' => DeliveryService::PING_SECONDS,
            'maxMinutes' => self::MAX_MINUTES,
        ];
    }

    /**
     * The claims of a valid token (signature and expiry checked), or null.
     *
     * @return array{t:string, o:int, u:int, e:int}|null
     */
    public static function verify(?string $token): ?array
    {
        if (! is_string($token) || ! preg_match('/^([A-Za-z0-9_-]{10,400})\.([A-Za-z0-9_-]{20,100})$/', $token, $m)) {
            return null;
        }
        if (! hash_equals(self::sign($m[1]), $m[2])) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($m[1], '-_', '+/'), true), true);
        if (! is_array($claims) || ! isset($claims['t'], $claims['o'], $claims['u'], $claims['e']) || (int) $claims['e'] < time()) {
            return null;
        }

        return ['t' => (string) $claims['t'], 'o' => (int) $claims['o'], 'u' => (int) $claims['u'], 'e' => (int) $claims['e']];
    }

    private static function sign(string $payload): string
    {
        return self::base64url(hash_hmac('sha256', 'native-track|'.$payload, (string) config('app.key'), true));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
