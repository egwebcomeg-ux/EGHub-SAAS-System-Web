<?php

namespace App\Services\Marketing;

use App\Jobs\SendMetaPurchase;
use App\Models\Order;
use App\Services\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Meta Conversions API: the server tells the restaurant's own Meta pixel about every order that was really placed,
 * next to the browser pixel (TrackingPixels). It brings back the orders the browser loses (iPhones, ad blockers) and
 * adds the ones no browser sees: phone calls and WhatsApp chats typed in by the call center. Both sides send the same
 * event id (eventId()), so Meta counts an order once.
 *
 * Only what Meta asks for matching leaves the server, and never in clear: the mobile, the first name and the
 * customer id go as SHA-256 hashes (Meta's "advanced matching"). The token is the restaurant's own (Events Manager),
 * stored encrypted; the restaurant's plan must include the tracking pixels.
 */
final class MetaConversions
{
    public const TOKEN = 'meta_capi_token';

    public const TEST_CODE = 'meta_test_event_code';

    /** The last answer from Meta, shown under the token on the settings page: {ok, at, error?}. */
    public const STATUS = 'meta_capi_status';

    /** order source => Meta action_source; the cashier and the kiosk are walk-in sales and are not sent. */
    public const ACTION_SOURCES = [
        'online' => 'website',
        'qr' => 'website',
        'callcenter' => 'phone_call',
        'whatsapp' => 'chat',
    ];

    public static function token(): ?string
    {
        $stored = Settings::get(self::TOKEN);
        if ($stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null; // APP_KEY changed: the owner pastes the token again
        }
    }

    public static function encrypt(string $token): string
    {
        return Crypt::encryptString(trim($token));
    }

    public static function configured(): bool
    {
        return TrackingPixels::ids()['meta'] !== '' && self::token() !== null;
    }

    /** The same id the browser pixel sends with its Purchase (menu.partials.pixels), so Meta drops the copy. */
    public static function eventId(Order $order): string
    {
        return 'order-'.$order->id;
    }

    /**
     * Queues the Purchase of a just-placed order, with what the guest's browser shows (only for the guest's own orders:
     * on a staff order the browser is the staff member's).
     */
    public static function orderPlaced(Order $order, ?Request $request = null): void
    {
        if (! isset(self::ACTION_SOURCES[$order->source]) || $order->status === 'cancelled' || ! self::configured()) {
            return;
        }
        $browser = [];
        if ($request && self::ACTION_SOURCES[$order->source] === 'website') {
            $browser = array_filter([
                'client_ip_address' => (string) $request->ip(),
                'client_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'fbp' => self::cookie($request, '_fbp'),
                'fbc' => self::cookie($request, '_fbc'),
            ]);
        }
        SendMetaPurchase::dispatch($order->id, $browser);
    }

    /**
     * The event as Meta's /events endpoint takes it.
     *
     * @param  array<string, string>  $browser
     * @return array<string, mixed>
     */
    public static function payload(Order $order, array $browser = []): array
    {
        $order->loadMissing('items');
        $hash = fn (string $value) => hash('sha256', $value);
        $mobile = normalize_phone((string) $order->customer_phone);
        $firstName = mb_strtolower(trim((string) strtok(trim((string) $order->customer_name), ' ')));

        $user = array_filter([
            'ph' => $mobile !== null ? [$hash(whatsapp_number($mobile))] : null,
            'fn' => $firstName !== '' ? [$hash($firstName)] : null,
            'country' => [$hash('eg')],
            'external_id' => $order->customer_id ? [$hash(tenant('id').':'.$order->customer_id)] : null,
        ]) + $browser;

        $contents = $order->items->map(fn ($line) => [
            'id' => (string) $line->menu_item_id, 'quantity' => (int) $line->quantity, 'item_price' => round((float) $line->unit_price, 2),
        ])->values()->all();

        $source = self::ACTION_SOURCES[$order->source];

        return array_filter([
            'event_name' => 'Purchase',
            'event_time' => $order->created_at->getTimestamp(),
            'event_id' => self::eventId($order),
            'action_source' => $source,
            'event_source_url' => $source === 'website' ? tenant()->url() : null,
            'user_data' => $user,
            'custom_data' => [
                'currency' => 'EGP',
                'value' => round((float) $order->total, 2),
                'order_id' => (string) $order->number,
                'content_type' => 'product',
                'content_ids' => array_column($contents, 'id'),
                'contents' => $contents,
                'num_items' => (int) $order->items->sum('quantity'),
            ],
        ], fn ($value) => $value !== null);
    }

    /**
     * Sends the order's Purchase now and remembers Meta's answer for the settings page.
     *
     * @param  array<string, string>  $browser
     */
    public static function send(Order $order, array $browser = []): bool
    {
        $pixel = TrackingPixels::ids()['meta'];
        $token = self::token();
        if ($pixel === '' || $token === null) {
            return false;
        }
        // the token goes in the body, never in the URL (an error message quotes the URL)
        $body = array_filter([
            'data' => [self::payload($order, $browser)],
            'test_event_code' => Settings::get(self::TEST_CODE) ?: null,
            'access_token' => $token,
        ]);
        try {
            $response = Http::timeout(15)->asJson()
                ->post('https://graph.facebook.com/'.config('services.meta_capi.version').'/'.$pixel.'/events', $body);
            $ok = $response->successful();
            $error = $ok ? null : mb_substr((string) ($response->json('error.message') ?? $response->status()), 0, 200);
        } catch (ConnectionException $e) {
            [$ok, $error] = [false, mb_substr($e->getMessage(), 0, 200)];
        }
        Settings::put([self::STATUS => json_encode(array_filter(['ok' => $ok, 'at' => now()->toIso8601String(), 'error' => $error], fn ($v) => $v !== null))]);

        return $ok;
    }

    /** @return array{ok?: bool, at?: string, error?: string} */
    public static function status(): array
    {
        return json_decode(Settings::get(self::STATUS), true) ?: [];
    }

    private static function cookie(Request $request, string $name): ?string
    {
        $value = (string) ($request->cookies->get($name) ?? '');

        return preg_match('/^fb\.\d\.\d+\.[A-Za-z0-9_.\-]{1,200}$/', $value) ? $value : null;
    }
}
