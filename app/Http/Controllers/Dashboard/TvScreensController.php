<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TvScreen;
use App\Models\TvSlide;
use App\Services\Entitlements;
use App\Services\Qr;
use App\Services\Settings;
use App\Support\ScreenDesigns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * TV menu boards (Business): each screen picks its categories and layout and gets its own link. Also the two small
 * helpers of the screens pages: the live status of every screen («بتعرض دلوقتي», refreshed by the page) and the
 * preview page of the ready designs. Staff tied to a branch reach only that branch's screens.
 */
class TvScreensController extends Controller
{
    /** The screens live on the "Screens & devices" page now (DevicesController); the old address opens its TV tab. */
    public function index(): RedirectResponse
    {
        return redirect()->route('dash.devices', ['tab' => 'tv']);
    }

    /** A new screen, within the screens the plan and the add-ons allow (Entitlements limit `screens`). */
    public function store(Request $request): RedirectResponse
    {
        $rights = Entitlements::current();
        if (! $rights->canAdd('screens')) {
            return back()->withInput()->withErrors(['screens' => __('You use all the screens in your subscription (:n). Add a screen to pair another one.', ['n' => (int) $rights->limit('screens')])]);
        }
        $data = $this->validated($request);
        if ($request->user()->branch_id) {
            $data['branch_id'] = $request->user()->branch_id;
        }
        $screen = TvScreen::query()->create($data + ['token' => random_token(10)]);

        return redirect()->route('dash.tv.edit', $screen)->with('ok', __('Screen saved. Now open eghub.app/tv on the TV and pair it with the code it shows.'));
    }

    public function update(Request $request, TvScreen $screen): RedirectResponse
    {
        abort_unless($screen->manageableBy($request->user()), 404);
        $data = $this->validated($request);
        if ($request->user()->branch_id) {
            $data['branch_id'] = $request->user()->branch_id;
        }
        $screen->update($data + ['is_active' => $request->boolean('is_active')]);
        $screen->touch(); // the TV reloads on its next check even when only the layout changed

        return back()->with('ok', __('Screen saved.'));
    }

    /** New link: the old one stops working (e.g. it was shared by mistake). */
    public function regenerate(Request $request, TvScreen $screen): RedirectResponse
    {
        abort_unless($screen->manageableBy($request->user()), 404);
        $screen->update(['token' => random_token(10)]);

        return back()->with('ok', __('New link created. Open it on the TV again.'));
    }

    public function destroy(Request $request, TvScreen $screen): RedirectResponse
    {
        abort_unless($screen->manageableBy($request->user()), 404);
        $screen->delete();

        return back()->with('ok', __('Screen deleted.'));
    }

    /** The live part of each screen's card (status, what it shows now), which the screens pages refresh every 15 s. */
    public function live(Request $request): JsonResponse
    {
        $screens = TvScreen::query()->manageableBy($request->user())->orderBy('id')->get();
        $ids = $screens->flatMap(fn (TvScreen $s) => array_values(array_diff_key((array) $s->now_showing, ['at' => 0])))->filter()->unique();
        $slides = TvSlide::query()->whereIn('id', $ids)->with(['media', 'item', 'offer'])->get()->keyBy('id');

        return response()->json(['screens' => $screens->mapWithKeys(fn (TvScreen $s) => [
            $s->id => view('dashboard.partials.tv-now', ['screen' => $s, 'nowSlides' => $slides])->render(),
        ])], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * One ready design drawn the way the player draws it, for the picker's small previews (an iframe each). The page
     * gets the restaurant's logo, colours and currency; the picker sends the item or the text typed (postMessage).
     */
    public function design(Request $request): Response
    {
        $settings = Settings::all();
        $color = fn (string $key, string $default) => is_hex_color($settings[$key] ?? null) ? $settings[$key] : $default;
        $key = in_array($request->query('key'), ScreenDesigns::keys(), true) ? (string) $request->query('key') : 'spotlight';

        return response()->view('screen.design-preview', [
            'key' => $key,
            'portrait' => $request->query('o') === 'portrait',
            'brand' => [
                'name' => tr($settings['brand_name'] ?? '', $settings['brand_name_en'] ?? '') ?: (string) tenant()->name,
                'logo' => ($settings['logo'] ?? '') ? '/storage/'.$settings['logo'] : null,
                'primary' => $color('color_primary', '#F26522'),
                'secondary' => $color('color_secondary', '#0A2540'),
                'currency' => tr($settings['currency'] ?? '', $settings['currency_en'] ?? ''),
                'qr' => Qr::svg(tenant()->url(), 300, $color('color_secondary', '#0A2540')),
            ],
            'labels' => ScreenDesigns::labels(),
            'lang' => app()->getLocale() === 'en' ? 'en' : 'ar',
        ])->withHeaders(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, max-age=300']);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'category_ids' => 'nullable|array|max:100',
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'layout' => ['required', Rule::in(TvScreen::LAYOUTS)],
            'columns' => 'required|integer|min:1|max:4',
            'rotate_seconds' => 'required|integer|min:5|max:120',
            'theme' => ['required', Rule::in(TvScreen::THEMES)],
        ]);
        $data['category_ids'] = array_values(array_map('intval', $data['category_ids'] ?? [])) ?: null;
        $data['show_images'] = $request->boolean('show_images');

        return $data;
    }
}
