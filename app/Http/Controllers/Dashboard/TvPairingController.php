<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TvScreen;
use App\Services\Entitlements;
use App\Services\Screens\ScreenPairing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «اربط شاشة»: the owner types the 6-digit code the TV shows on eghub.app/screen (or scans its QR, which opens this
 * page with the code filled in) and picks which screen it is. Wrong codes are counted per restaurant
 * (FAILED_ATTEMPTS in FAILED_MINUTES) on top of the route throttle, so codes cannot be guessed from a dashboard.
 * Not for EgWeb support signed in from the admin (a device stays attached after the session).
 */
class TvPairingController extends Controller
{
    public const FAILED_ATTEMPTS = 8;

    public const FAILED_MINUTES = 10;

    public function show(Request $request): View
    {
        $screens = TvScreen::query()->orderBy('id')->get();
        $limit = Entitlements::current()->limit('screens');

        return view('dashboard.tv-pair', [
            'code' => preg_replace('/\D/', '', (string) $request->query('code', '')),
            'screens' => $screens,
            'selected' => (int) $request->query('screen', 0) ?: ($screens->firstWhere(fn (TvScreen $s) => ! $s->isPaired())?->id ?? $screens->first()?->id),
            'limit' => $limit,
            'paired' => ScreenPairing::pairedCount(),
            'canAdd' => Entitlements::current()->canAdd('screens'),
        ]);
    }

    public function store(Request $request, ScreenPairing $pairing): RedirectResponse
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'code' => 'required|string|max:12',
            'screen_id' => ['required', 'integer', Rule::exists('tv_screens', 'id')->where('tenant_id', $tenant)],
        ]);
        $key = 'tv-pair-failed:'.$tenant;
        if (RateLimiter::tooManyAttempts($key, self::FAILED_ATTEMPTS)) {
            return back()->withInput()->withErrors(['code' => __('Too many wrong codes. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
        }
        $screen = TvScreen::query()->findOrFail($data['screen_id']);
        try {
            $claimed = $pairing->claim($data['code'], $screen);
        } catch (\DomainException) {
            return back()->withInput()->withErrors(['code' => __('You use all the screens in your subscription (:n). Add a screen to pair another one.', ['n' => (int) Entitlements::current()->limit('screens')])]);
        }
        if (! $claimed) {
            RateLimiter::hit($key, self::FAILED_MINUTES * 60);

            return back()->withInput()->withErrors(['code' => __('This code is wrong or expired. Check the number on the TV (it changes every 10 minutes).')]);
        }

        return redirect()->route('dash.tv.edit', $screen)->with('ok', __('The screen is paired. It starts playing in a few seconds.'));
    }
}
