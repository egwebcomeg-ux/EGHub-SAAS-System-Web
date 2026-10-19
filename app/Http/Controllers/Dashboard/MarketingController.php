<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\Season;
use App\Services\ImageService;
use App\Services\Orders\CartGoal;
use App\Services\Settings;
use App\Support\MenuTicker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Timed offers ("happy hour"), the announcements of the public menu (banners, the popup banner, the ticker), seasons. */
class MarketingController extends Controller
{
    public function index(): View
    {
        return view('dashboard.marketing', [
            'offers' => Offer::query()->with(['category', 'branch'])->latest('id')->get(),
            'banners' => Banner::query()->with(['item', 'category'])->orderByDesc('is_popup')->orderBy('sort_order')->orderBy('id')->get(),
            'maxBanners' => Banner::MAX_ACTIVE,
            'categories' => Category::query()->orderBy('sort_order')->get(),
            'branches' => Branch::query()->orderBy('sort_order')->get(),
            'items' => MenuItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'price']),
            'seasons' => Season::query()->orderByDesc('starts_on')->orderByDesc('id')->get(),
            'liveSeason' => Season::live(),
            'settings' => Settings::all(),
        ]);
    }

    public function saveOffer(Request $request, ?Offer $offer = null): RedirectResponse
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'title' => 'required|string|max:80', 'title_en' => 'nullable|string|max:80',
            'discount_percent' => 'required|integer|min:1|max:90',
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'days' => 'array', 'days.*' => 'integer|between:0,6',
            'starts_at' => 'nullable|date_format:H:i', 'ends_at' => 'nullable|date_format:H:i|required_with:starts_at',
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on',
        ]);
        $values = $data + ['title_en' => '', 'days' => null, 'is_active' => $request->boolean('is_active', true)];
        $values['days'] = isset($data['days']) && count($data['days']) < 7 ? array_values(array_map('intval', $data['days'])) : null;
        ($offer?->exists ? $offer : new Offer)->fill($values)->save();

        return back()->with('ok', __('Offer saved.'));
    }

    public function toggleOffer(Offer $offer): RedirectResponse
    {
        $offer->update(['is_active' => ! $offer->is_active]);

        return back();
    }

    public function deleteOffer(Offer $offer): RedirectResponse
    {
        $offer->delete();

        return back()->with('ok', __('Offer deleted.'));
    }

    /**
     * A new banner: picture (required), optional title and one optional link (item, category or address). With
     * "show as soon as the menu opens" it is the menu's popup instead of a slide: switching it on pauses the popup
     * that was on (one at a time), and it does not count towards the strip's limit.
     */
    public function saveBanner(Request $request, ImageService $images): RedirectResponse
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'image' => 'required|file|max:5120',
            'title' => 'nullable|string|max:80', 'title_en' => 'nullable|string|max:80',
            'link_type' => ['required', Rule::in(['none', 'item', 'category', 'url'])],
            'menu_item_id' => ['nullable', 'required_if:link_type,item', Rule::exists('menu_items', 'id')->where('tenant_id', $tenant)],
            'category_id' => ['nullable', 'required_if:link_type,category', Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'link_url' => ['nullable', 'required_if:link_type,url', 'url', 'max:300'],
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'sort_order' => 'nullable|integer|min:0|max:99',
            'popup_frequency' => ['nullable', Rule::in(Banner::POPUP_FREQUENCIES)],
        ]);
        $popup = $request->boolean('is_popup');
        if (! $popup && $this->liveBanners() >= Banner::MAX_ACTIVE) {
            return back()->withErrors(['banners' => __('Up to :n banners can be active at a time. Pause or delete one first.', ['n' => Banner::MAX_ACTIVE])]);
        }
        if ($popup) {
            $this->pauseOtherPopups();
        }
        Banner::query()->create([
            'is_popup' => $popup,
            'popup_frequency' => $data['popup_frequency'] ?? 'session',
            'image' => $images->store($request->file('image'), 'banner'),
            'title' => (string) ($data['title'] ?? ''), 'title_en' => (string) ($data['title_en'] ?? ''),
            'menu_item_id' => $data['link_type'] === 'item' ? (int) $data['menu_item_id'] : null,
            'category_id' => $data['link_type'] === 'category' ? (int) $data['category_id'] : null,
            'link_url' => $data['link_type'] === 'url' ? $data['link_url'] : null,
            'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? ((int) Banner::query()->max('sort_order') + 1)),
        ]);

        return back()->with('ok', $popup ? __('Saved. It shows as soon as the menu opens.') : __('Banner saved.'));
    }

    public function toggleBanner(Banner $banner): RedirectResponse
    {
        if (! $banner->is_active && ! $banner->is_popup && $this->liveBanners() >= Banner::MAX_ACTIVE) {
            return back()->withErrors(['banners' => __('Up to :n banners can be active at a time. Pause or delete one first.', ['n' => Banner::MAX_ACTIVE])]);
        }
        if (! $banner->is_active && $banner->is_popup) {
            $this->pauseOtherPopups();
        }
        $banner->update(['is_active' => ! $banner->is_active]);

        return back();
    }

    public function deleteBanner(Banner $banner, ImageService $images): RedirectResponse
    {
        $images->delete($banner->image);
        $banner->delete();

        return back()->with('ok', __('Banner deleted.'));
    }

    /** Banners of the strip switched on and not yet ended (a future start still counts towards the limit). */
    private function liveBanners(): int
    {
        return Banner::query()->where('is_active', true)->where('is_popup', false)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()))->count();
    }

    /** One popup at a time: the one being switched on replaces the one that was on. */
    private function pauseOtherPopups(): void
    {
        Banner::query()->where('is_popup', true)->where('is_active', true)->update(['is_active' => false]);
    }

    /** The old popup editor is now part of "Banners & announcements" (a banner shown as soon as the menu opens). */
    public function popupsMoved(): RedirectResponse
    {
        return redirect()->to(route('dash.marketing').'#announcements')
            ->with('ok', __('Popups are now banners shown as soon as the menu opens.'));
    }

    /** The cart goal of the menu: threshold and reward (a free item, or a percent / amount off). */
    public function saveCartGoal(Request $request): RedirectResponse
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'cart_goal_amount' => 'required_if:cart_goal_enabled,1|nullable|numeric|min:1|max:100000',
            'cart_goal_reward' => ['required', Rule::in(CartGoal::REWARDS)],
            'cart_goal_item' => ['nullable', 'required_if:cart_goal_reward,item', Rule::exists('menu_items', 'id')->where('tenant_id', $tenant)],
            'cart_goal_value' => ['nullable', 'required_unless:cart_goal_reward,item', 'numeric', 'min:1',
                $request->input('cart_goal_reward') === 'percent' ? 'max:100' : 'max:100000'],
            'cart_goal_label' => 'nullable|string|max:120',
        ]);
        Settings::put([
            'cart_goal_enabled' => $request->boolean('cart_goal_enabled') ? '1' : '0',
            'cart_goal_amount' => (string) (float) ($data['cart_goal_amount'] ?? 0),
            'cart_goal_reward' => $data['cart_goal_reward'],
            'cart_goal_item' => (string) ($data['cart_goal_item'] ?? ''),
            'cart_goal_value' => (string) (float) ($data['cart_goal_value'] ?? 0),
            'cart_goal_label' => trim((string) ($data['cart_goal_label'] ?? '')),
            'cart_goal_with_coupon' => $request->boolean('cart_goal_with_coupon') ? '1' : '0',
        ]);

        return back()->with('ok', __('Cart goal saved.'));
    }

    /**
     * The owner's lines of the menu ticker (up to five; empty lines are dropped), each with an optional link to one
     * of this restaurant's items or categories.
     */
    public function saveTicker(Request $request): RedirectResponse
    {
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'lines' => 'nullable|array|max:'.MenuTicker::MAX_OWNER_LINES,
            'lines.*.text' => 'nullable|string|max:'.MenuTicker::MAX_LENGTH,
            'lines.*.text_en' => 'nullable|string|max:'.MenuTicker::MAX_LENGTH,
            'lines.*.link' => ['nullable', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail) use ($tenant) {
                [$type, $id] = array_pad(explode(':', (string) $value, 2), 2, '');
                $table = ['item' => 'menu_items', 'category' => 'categories'][$type] ?? null;
                $ok = $table !== null && ctype_digit($id)
                    && DB::table($table)->where('tenant_id', $tenant)->where('id', (int) $id)->exists();
                if (! $ok) {
                    $fail(__('Choose an item or a category of your menu.'));
                }
            }],
        ]);
        $lines = collect($data['lines'] ?? [])
            ->map(fn (array $line) => [
                'text' => trim((string) ($line['text'] ?? '')),
                'text_en' => trim((string) ($line['text_en'] ?? '')),
                'link' => (string) ($line['link'] ?? ''),
            ])
            ->filter(fn (array $line) => $line['text'] !== '' || $line['text_en'] !== '')
            ->values()->all();
        Settings::put(['menu_ticker_lines' => $lines === [] ? '' : json_encode($lines, JSON_UNESCAPED_UNICODE)]);

        return back()->with('ok', __('Ticker saved.'));
    }

    /** The banner strip as a slideshow (autoplay) or a strip the guest swipes. */
    public function saveBannerAutoplay(Request $request): RedirectResponse
    {
        Settings::put(['banner_autoplay' => $request->boolean('banner_autoplay') ? '1' : '0']);

        return back()->with('ok', __('Settings saved.'));
    }

    /** A seasonal look; empty greeting / accent take the preset's defaults. */
    public function saveSeason(Request $request, ImageService $images): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'preset' => ['required', Rule::in(array_keys(Season::PRESETS))],
            'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on',
            'greeting' => 'nullable|string|max:120', 'greeting_en' => 'nullable|string|max:120',
            'accent' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'banner' => 'nullable|file|max:5120', 'logo' => 'nullable|file|max:5120',
        ]);
        $preset = Season::PRESETS[$data['preset']];
        $greeting = trim((string) ($data['greeting'] ?? ''));
        $greetingEn = trim((string) ($data['greeting_en'] ?? ''));
        Season::query()->create([
            'name' => $data['name'], 'preset' => $data['preset'],
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
            'greeting' => $greeting !== '' ? $greeting : $preset['greeting'],
            'greeting_en' => $greetingEn !== '' ? $greetingEn : $preset['greeting_en'],
            'accent' => isset($data['accent']) ? strtoupper($data['accent']) : $preset['accent'],
            'decoration' => $request->boolean('decoration'),
            'banner' => $request->hasFile('banner') ? $images->store($request->file('banner'), 'banner') : null,
            'logo' => $request->hasFile('logo') ? $images->store($request->file('logo'), 'logo') : null,
        ]);

        return back()->with('ok', __('Season saved.'));
    }

    public function toggleSeason(Season $season): RedirectResponse
    {
        $season->update(['is_active' => ! $season->is_active]);

        return back();
    }

    public function deleteSeason(Season $season, ImageService $images): RedirectResponse
    {
        $images->delete($season->banner);
        $images->delete($season->logo);
        $season->delete();

        return back()->with('ok', __('Season deleted.'));
    }
}
