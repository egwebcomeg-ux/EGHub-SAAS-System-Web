<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\TvScreen;
use App\Services\Qr;
use App\Services\Screens\ScreenAlerts;
use App\Services\Screens\ScreenPairing;
use App\Services\Screens\ScreenPlaylist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The universal screen player on the main domain (eghub.app/screen, short eghub.app/tv): any TV browser, TV box or
 * the EgHub Android TV app opens it. Unpaired it shows a 6-digit code and a QR; once paired it plays its screen's
 * playlist and keeps it offline (service worker /screen/sw.js). Every data endpoint is read-only and answers only for
 * the screen of the device token sent with it (ScreenPairing::device), inside that screen's restaurant.
 */
class ScreenPlayerController extends Controller
{
    /** Bumped when the player page changes, so the service worker drops its old copy. */
    public const VERSION = '1.1.0';

    private const NO_STORE = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow'];

    public function show(): Response
    {
        return response()->view('screen.player', [
            'mode' => 'device', 'boot' => null, 'base' => '/screen', 'version' => self::VERSION,
        ])->withHeaders(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-cache']);
    }

    public function serviceWorker(): Response
    {
        return response(view('screen.sw', ['version' => self::VERSION])->render(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8', 'Service-Worker-Allowed' => '/screen',
            'Cache-Control' => 'no-cache', 'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** A new code for this device to show (throttled per address). */
    public function startPairing(Request $request, ScreenPairing $pairing): JsonResponse
    {
        $started = $pairing->start($request);
        $link = route('dash.tv.pair', ['code' => $started['code']]);

        return response()->json([
            'code' => $started['code'], 'secret' => $started['secret'], 'expires_in' => ScreenPairing::CODE_MINUTES * 60,
            'qr' => Qr::svg($link, 240, '#0A2540'), 'link' => $link,
        ], 200, self::NO_STORE);
    }

    /** The device checks whether its code was typed in the dashboard; the token comes once, with its cookie. */
    public function pairStatus(Request $request, ScreenPairing $pairing): JsonResponse
    {
        $result = $pairing->collect((string) $request->header('X-Pair-Secret', ''));
        $response = response()->json($result, 200, self::NO_STORE);
        if (isset($result['token'])) {
            $response->headers->setCookie($this->cookie($request, $result['token']));
        }

        return $response;
    }

    public function playlist(Request $request, ScreenPairing $pairing): JsonResponse
    {
        $screen = $this->screen($request, $pairing);
        if ($screen instanceof JsonResponse) {
            return $screen;
        }

        return response()->json((new ScreenPlaylist($screen))->payload(), 200, self::NO_STORE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The lightweight check the player makes every POLL_SECONDS; it also tells the dashboard the screen is on. */
    public function version(Request $request, ScreenPairing $pairing): JsonResponse
    {
        $screen = $this->screen($request, $pairing);
        if ($screen instanceof JsonResponse) {
            return $screen;
        }
        $this->seen($screen);

        return response()->json([
            'v' => (new ScreenPlaylist($screen))->version(), 'reload' => $screen->reload_requested_at?->timestamp,
            'now' => (int) floor(microtime(true) * 1000), 'tz' => now()->utcOffset(),
        ], 200, self::NO_STORE);
    }

    /** The fast check (every ScreenAlerts::POLL_SECONDS): the urgent message, a reload, and what the device shows now. */
    public function live(Request $request, ScreenPairing $pairing): JsonResponse
    {
        $screen = $this->screen($request, $pairing);
        if ($screen instanceof JsonResponse) {
            return $screen;
        }
        $this->seen($screen);
        $alerts = new ScreenAlerts($screen);
        $alerts->report((string) $request->query('showing', ''));

        return response()->json($alerts->payload(), 200, self::NO_STORE, JSON_UNESCAPED_UNICODE);
    }

    public function ready(Request $request, ScreenPairing $pairing): JsonResponse
    {
        $screen = $this->screen($request, $pairing);
        if ($screen instanceof JsonResponse) {
            return $screen;
        }
        $branch = $request->integer('branch') ?: null;

        return response()->json((new ScreenPlaylist($screen))->readyNumbers($branch), 200, self::NO_STORE);
    }

    /** Every minute: what the device is (app version, type, resolution) for the dashboard; nothing personal. */
    public function heartbeat(Request $request, ScreenPairing $pairing): JsonResponse
    {
        $screen = $this->screen($request, $pairing, allowPaused: true);
        if ($screen instanceof JsonResponse) {
            return $screen;
        }
        $clean = fn ($value, int $max) => mb_substr(preg_replace('/[^\pL\pN .,:;_\-\/()+x]/u', '', (string) $value), 0, $max);
        $info = [
            'app' => $clean($request->input('app'), 20),
            'device' => $clean($request->input('device'), 40),
            'model' => $clean($request->input('model'), 60),
            'screen' => $clean($request->input('screen'), 20),
            'online' => $request->boolean('online', true),
            'agent' => $clean($request->userAgent(), 160),
        ];
        $screen->writeQuietly(['last_seen_at' => now(), 'device_info' => $info]);

        return response()->json(['ok' => true, 'reload' => $screen->reload_requested_at?->timestamp], 200, self::NO_STORE);
    }

    /** A fresh token for the same screen (the player asks now and then); the old one stops working at once. */
    public function rotate(Request $request, ScreenPairing $pairing): JsonResponse
    {
        $screen = $this->screen($request, $pairing, allowPaused: true);
        if ($screen instanceof JsonResponse) {
            return $screen;
        }
        $token = random_token(32);
        $screen->writeQuietly(['device_token_hash' => TvScreen::hashDeviceToken($token)]);
        $response = response()->json(['token' => $token], 200, self::NO_STORE);
        $response->headers->setCookie($this->cookie($request, $token));

        return $response;
    }

    /** The device's screen, or the JSON answer the player understands (unpaired → show a code; paused → wait). */
    private function screen(Request $request, ScreenPairing $pairing, bool $allowPaused = false): TvScreen|JsonResponse
    {
        $screen = $pairing->device($request);
        if (! $screen) {
            return response()->json(['state' => 'unpaired'], 401, self::NO_STORE);
        }
        if (! $allowPaused && ! ScreenPairing::mayPlay($screen)) {
            $this->seen($screen);

            return response()->json(['state' => 'paused', 'message' => __('This screen is paused. Check the subscription in the dashboard.')], 403, self::NO_STORE);
        }

        return $screen;
    }

    /** last_seen_at at most twice a minute, without touching the screen's updated_at. */
    private function seen(TvScreen $screen): void
    {
        if (! $screen->last_seen_at || $screen->last_seen_at->lt(now()->subSeconds(30))) {
            $screen->writeQuietly(['last_seen_at' => now()]);
        }
    }

    private function cookie(Request $request, string $token): Cookie
    {
        return cookie(ScreenPairing::COOKIE, $token, 60 * 24 * 365 * 5, '/screen', null, $request->isSecure(), true, false, 'lax');
    }
}
