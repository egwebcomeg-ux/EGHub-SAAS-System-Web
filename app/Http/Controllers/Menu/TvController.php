<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\ScreenPlayerController;
use App\Models\TvScreen;
use App\Services\Entitlements;
use App\Services\Screens\ScreenAlerts;
use App\Services\Screens\ScreenPlaylist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The older secret link of a TV screen (/tv/{token} on the menu's address): it keeps working and now opens the same
 * player as eghub.app/screen with the screen's playlist, without pairing (the link is the key). The page carries the
 * first playlist so it shows at once; then it asks /version every POLL_SECONDS and swaps the slides when it changes.
 */
class TvController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow'];

    public function show(string $token): Response
    {
        $screen = $this->screen($token);

        return response()->view('screen.player', [
            'mode' => 'link', 'boot' => (new ScreenPlaylist($screen))->payload(), 'base' => null,
            'version' => ScreenPlayerController::VERSION,
        ])->withHeaders(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-cache']);
    }

    public function playlist(string $token): JsonResponse
    {
        return response()->json((new ScreenPlaylist($this->screen($token)))->payload(), 200, self::NO_STORE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function version(string $token): JsonResponse
    {
        $screen = $this->screen($token);

        return response()->json([
            'v' => (new ScreenPlaylist($screen))->version(), 'reload' => $screen->reload_requested_at?->timestamp,
            'now' => (int) floor(microtime(true) * 1000), 'tz' => local_at()->utcOffset(),
        ], 200, self::NO_STORE);
    }

    /** The urgent message and a reload, every ScreenAlerts::POLL_SECONDS (the link is not a paired device: no report). */
    public function live(string $token): JsonResponse
    {
        return response()->json((new ScreenAlerts($this->screen($token)))->payload(), 200, self::NO_STORE, JSON_UNESCAPED_UNICODE);
    }

    public function ready(Request $request, string $token): JsonResponse
    {
        return response()->json((new ScreenPlaylist($this->screen($token)))->readyNumbers($request->integer('branch') ?: null), 200, self::NO_STORE);
    }

    private function screen(string $token): TvScreen
    {
        abort_unless(Entitlements::current()->allows('tv_screens'), 404);

        return TvScreen::query()->where('token', $token)->where('is_active', true)->firstOrFail();
    }
}
