<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Services\Audit;
use App\Services\BrandAssets;
use App\Services\Entitlements;
use App\Services\ImageService;
use App\Services\Import\Ai\AiException;
use App\Services\Import\Ai\AiProviders;
use App\Services\Marketing\TrackingPixels;
use App\Services\Notify\GuestNotifier;
use App\Services\Orders\AutoCancel;
use App\Services\Orders\OrderConfirmation;
use App\Services\Settings;
use App\Support\BusinessType;
use App\Support\Cities;
use App\Support\Cuisines;
use App\Support\DineIn;
use App\Support\ItemBadges;
use App\Support\MenuFonts;
use App\Support\MenuHeader;
use App\Support\MenuIntro;
use App\Support\MenuLayouts;
use App\Support\MenuLocales;
use App\Support\MenuThemes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Brand, look, contact and ordering settings of the restaurant. */
class SettingsController extends Controller
{
    public const FONTS = MenuFonts::NAMES;

    /** The tabs of the page (one form: every tab is sent and saved together, so nothing is lost between tabs). */
    public const TABS = ['identity', 'menu', 'orders', 'messages', 'languages', 'account'];

    public function edit(Request $request): View
    {
        $tab = $request->old('settings_tab', $request->query('tab'));

        return view('dashboard.settings', [
            'tab' => in_array($tab, self::TABS, true) ? $tab : self::TABS[0],
            'tabs' => array_combine(self::TABS, [__('Identity'), __('Menu'), __('Orders'), __('Messages'), __('Languages'), __('Account & security')]),
            's' => Settings::all(), 'fonts' => self::FONTS, 'branding' => Entitlements::current()->allows('full_branding'),
            'themes' => Entitlements::current()->allows('menu_themes'),
            'whatsapp' => Entitlements::current()->allows('whatsapp_notifications'),
            // the AI key also translates the menu into the extra languages
            'ai' => Entitlements::current()->allows('ai_import|extra_languages'), 'providers' => AiProviders::PROVIDERS, 'aiKey' => AiProviders::masked(),
            'extraLanguages' => Entitlements::current()->allows('extra_languages'),
            'advancedReports' => Entitlements::current()->allows('advanced_reports'),
            'pixels' => TrackingPixels::enabled(),
        ]);
    }

    public function update(Request $request, ImageService $images): RedirectResponse
    {
        // pixel ids as the providers show them (upper-case, no spaces) before they are checked
        foreach (array_keys(TrackingPixels::RULES) as $key) {
            if ($request->has($key)) {
                $request->merge([$key => strtoupper(trim((string) $request->input($key)))]);
            }
        }
        $data = $request->validate([
            'brand_name' => 'required|string|max:80',
            'brand_name_en' => 'nullable|string|max:80',
            'tagline' => 'nullable|string|max:150',
            'tagline_en' => 'nullable|string|max:150',
            'currency' => 'required|string|max:10',
            'currency_en' => 'required|string|max:10',
            'color_primary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_secondary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_bg' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font' => ['required', Rule::in(self::FONTS)],
            'default_locale' => ['required', Rule::in(['ar', 'en'])],
            'menu_theme' => ['nullable', Rule::in(MenuThemes::keys())],
            'business_type' => ['nullable', Rule::in(array_keys(BusinessType::TYPES))],
            'phone' => 'nullable|string|max:20',
            'whatsapp' => 'nullable|string|max:20',
            'instagram' => 'nullable|url|max:200',
            'facebook' => 'nullable|url|max:200',
            'tiktok' => 'nullable|url|max:200',
            'google_review_url' => 'nullable|url|max:300',
            'city' => ['nullable', Rule::in(array_keys(Cities::ALL))],
            'area' => 'nullable|string|max:60',
            'cuisine' => 'nullable|array|max:6',
            'cuisine.*' => [Rule::in(array_keys(Cuisines::ALL))],
            'short_description' => 'nullable|string|max:160',
            'short_description_en' => 'nullable|string|max:160',
            'service_percent' => 'nullable|numeric|min:0|max:30',
            'online_prep_minutes' => 'nullable|integer|min:5|max:240',
            'online_auto_cancel_minutes' => 'nullable|integer|min:0|max:'.AutoCancel::MAX_MINUTES,
            'tax_percent' => 'nullable|numeric|min:0|max:30',
            'ai_provider' => ['nullable', Rule::in(array_keys(AiProviders::PROVIDERS))],
            'ai_model' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'ai_key' => 'nullable|string|max:300',
            'wa_sender_name' => 'nullable|string|max:40',
            'wa_order_confirm_minutes' => 'nullable|integer|min:1|max:'.OrderConfirmation::MAX_MINUTES,
            'logo' => 'nullable|file|max:5120',
            'cover' => 'nullable|file|max:5120',
            'menu_locales' => 'nullable|array',
            'menu_locales.*' => [Rule::in(MenuLocales::codes())],
            'qr_target' => ['nullable', Rule::in(['menu', 'links'])],
            // sending orders from the table needs table ordering in the plan; a list for the waiter works on every plan
            'dine_in_mode' => ['nullable', Rule::in(DineIn::MODES), function (string $attribute, mixed $value, \Closure $fail) {
                if ($value === 'order' && ! DineIn::orderingAllowed()) {
                    $fail(__('Sending orders from the table needs table ordering in your plan. Choose the list for the waiter.'));
                }
            }],
            'dine_in_notice' => 'nullable|string|max:200',
            'menu_intro' => ['nullable', Rule::in(MenuIntro::STYLES)],
            'menu_intro_repeat' => ['nullable', Rule::in(['always', 'session'])],
            'intro_background' => ['nullable', Rule::in(MenuIntro::BACKGROUNDS)],
            'header_background' => ['nullable', Rule::in(MenuIntro::BACKGROUNDS)],
            'header_show_name' => ['nullable', Rule::in(MenuHeader::CHOICES)],
            'intro_mascot' => 'nullable|file|max:2048|extensions:svg,png,webp,jpg,jpeg',
            'intro_mascot_speed' => 'nullable|file|max:2048|extensions:svg,png,webp,jpg,jpeg',
            'intro_slogan' => 'nullable|file|max:2048|extensions:svg,png,webp,jpg,jpeg',
            'intro_pattern' => 'nullable|file|max:2048|extensions:svg,png,webp,jpg,jpeg',
            'logo_background' => ['nullable', Rule::in(['auto', 'white', 'none'])],
            'menu_layout' => ['nullable', Rule::in(MenuLayouts::CHOICES)],
            'menu_display' => ['nullable', Rule::in(['simple', 'pro'])],
            'menu_category_style' => ['nullable', Rule::in(['chips', 'circles'])],
            'menu_badge_filters' => 'nullable|array|max:'.ItemBadges::MAX_FILTERS,
            'menu_badge_filters.*' => [Rule::in(array_keys(MenuItem::TAGS))],
            'meta_pixel_id' => ['nullable', 'string', TrackingPixels::RULES['meta_pixel_id']],
            'ga4_measurement_id' => ['nullable', 'string', TrackingPixels::RULES['ga4_measurement_id']],
            'tiktok_pixel_id' => ['nullable', 'string', TrackingPixels::RULES['tiktok_pixel_id']],
        ], [], ['meta_pixel_id' => 'Meta Pixel ID', 'ga4_measurement_id' => 'GA4 Measurement ID', 'tiktok_pixel_id' => 'TikTok Pixel ID']);

        $values = collect($data)->except(['logo', 'cover', 'ai_key', 'cuisine', 'menu_locales', 'menu_badge_filters', ...array_keys(BrandAssets::PIECES)])->map(fn ($v) => (string) ($v ?? ''))->all();
        if (($values['qr_target'] ?? '') === '') {
            unset($values['qr_target']); // not sent: keep the current target
        }
        // tracking pixels are only saved with the feature (the form sends them then)
        if (TrackingPixels::enabled() && $request->has('pixels_sent')) {
            foreach (array_keys(TrackingPixels::RULES) as $key) {
                $values[$key] = (string) ($data[$key] ?? '');
            }
        } else {
            foreach (array_keys(TrackingPixels::RULES) as $key) {
                unset($values[$key]);
            }
        }
        $values['cuisine'] = implode(',', array_values(array_unique($data['cuisine'] ?? [])));
        $values['listed_in_directory'] = $request->boolean('listed_in_directory') ? '1' : '0';
        // the key is only replaced when a new one is typed, and is stored encrypted
        if (! empty($data['ai_key'])) {
            $values['ai_key'] = AiProviders::encrypt($data['ai_key']);
        } elseif ($request->boolean('remove_ai_key')) {
            $values['ai_key'] = '';
        }
        if (($values['business_type'] ?? '') === '') {
            unset($values['business_type']); // not sent: keep the current type
        }
        $values['english_enabled'] = $request->boolean('english_enabled') ? '1' : '0';
        $values['ordering_enabled'] = $request->boolean('ordering_enabled') ? '1' : '0';
        // the hall: kept empty while it equals the default, so a later plan upgrade turns table ordering on by itself
        unset($values['dine_in_mode'], $values['menu_intro']);
        // the intro colours: not sent = keep them
        if (($values['header_background'] ?? '') === '') {
            unset($values['header_background']);
        }
        if (($values['header_show_name'] ?? '') === '') {
            unset($values['header_show_name']);
        }
        if (($values['intro_background'] ?? '') === '') {
            unset($values['intro_background']);
        }
        if ($request->boolean('dine_in_sent')) {
            $next = $values + Settings::all();
            $mode = (string) ($data['dine_in_mode'] ?? '');
            $values['dine_in_mode'] = $mode === '' || $mode === DineIn::defaultMode($next) ? '' : $mode;
            $effective = DineIn::mode(['dine_in_mode' => $values['dine_in_mode']] + $next);
            $prices = $request->boolean('dine_in_show_prices');
            $values['dine_in_show_prices'] = $prices === DineIn::defaultShowPrices($effective) ? '' : ($prices ? '1' : '0');
            $values['order_type_prompt'] = $request->boolean('order_type_prompt') ? '1' : '0';
            $values['dine_in_hide_total'] = $request->boolean('dine_in_hide_total') ? '1' : '0';
        } else {
            unset($values['dine_in_notice']);
        }
        if (! empty($data['menu_intro'])) {
            $values['menu_intro'] = $data['menu_intro'];
        }
        if (! empty($data['menu_intro_repeat'])) {
            $values['menu_intro_repeat'] = $data['menu_intro_repeat'];
        }
        // the logo tile and the photo placeholders (sent with the brand section)
        if (($values['logo_background'] ?? '') === '') {
            unset($values['logo_background']);
        }
        if ($request->boolean('look_sent')) {
            $values['item_placeholder'] = $request->boolean('item_placeholder') ? '1' : '0';
            $values['menu_best_sellers'] = $request->boolean('menu_best_sellers') ? '1' : '0';
        }
        // the category row (chips or circles): not sent = keep it
        if (($values['menu_category_style'] ?? '') === '') {
            unset($values['menu_category_style']);
        }
        if (Entitlements::current()->allows('online_ordering')) {
            $values['online_takeaway'] = $request->boolean('online_takeaway') ? '1' : '0';
            $values['online_delivery'] = $request->boolean('online_delivery') ? '1' : '0';
            $values['online_auto_cancel_minutes'] = (string) (int) ($data['online_auto_cancel_minutes'] ?? 0);
        } else {
            unset($values['online_auto_cancel_minutes']);
        }
        // WhatsApp messages to guests: each toggle switches one message type on
        if (Entitlements::current()->allows('whatsapp_notifications')) {
            foreach (GuestNotifier::TOGGLES as $setting) {
                $values[$setting] = $request->boolean($setting) ? '1' : '0';
            }
            if (($values['wa_order_confirm_minutes'] ?? '') === '') {
                unset($values['wa_order_confirm_minutes']);
            }
        } else {
            unset($values['wa_sender_name'], $values['wa_order_confirm_minutes']);
        }
        if (($values['online_prep_minutes'] ?? '') === '') {
            unset($values['online_prep_minutes']);
        }

        // extra menu languages and the weekly report are only saved while their feature is on (the form sends them then)
        if (Entitlements::current()->allows('extra_languages') && $request->boolean('menu_locales_sent')) {
            $values['menu_locales'] = json_encode(array_values(array_intersect(MenuLocales::codes(), $data['menu_locales'] ?? [])));
        }
        if (Entitlements::current()->allows('advanced_reports') && $request->has('weekly_report_email')) {
            $values['weekly_report_email'] = $request->boolean('weekly_report_email') ? '1' : '0';
        }

        // two-factor for every staff member: only the owner decides (managers reach this page too)
        if ($request->user()->isOwner() && $request->has('require_2fa')) {
            $values['require_2fa'] = $request->boolean('require_2fa') ? '1' : '0';
        }
        // the onboarding checklist counts "ordering on/off decided" once this form was saved
        $values['ordering_decided'] = '1';

        // the item layout and the display mode are picked with the design (same plan feature); not sent: keep them
        foreach (['menu_layout', 'menu_display'] as $key) {
            if (! Entitlements::current()->allows('menu_themes') || ($values[$key] ?? '') === '') {
                unset($values[$key]);
            }
        }
        // badge filter chips of the menu (sent with the design section); none ticked = off
        if ($request->boolean('badge_filters_sent')) {
            $values['menu_badge_filters'] = json_encode(array_values(array_unique($data['menu_badge_filters'] ?? [])));
        }
        if (! Entitlements::current()->allows('menu_themes')) {
            unset($values['menu_theme']);
        }
        // Colors, fonts and logo are part of the branding feature; without it the EgHub defaults stay.
        if (! Entitlements::current()->allows('full_branding')) {
            foreach (['color_primary', 'color_secondary', 'color_bg', 'font'] as $key) {
                unset($values[$key]);
            }
        }
        foreach (['logo', 'cover'] as $key) {
            if ($request->hasFile($key)) {
                $images->delete(Settings::get($key));
                $values[$key] = $images->store($request->file($key), $key);
            } elseif ($request->boolean("remove_$key")) {
                $images->delete(Settings::get($key));
                $values[$key] = '';
            }
        }
        // the mascot intro pieces (PNG / WebP / JPEG, or a sanitised SVG) and the header pattern + peeking character
        $brandAssets = app(BrandAssets::class);
        foreach (array_keys(BrandAssets::PIECES) as $key) {
            if ($request->hasFile($key)) {
                $brandAssets->delete(Settings::get($key));
                $values[$key] = $brandAssets->store($request->file($key));
            } elseif ($request->boolean("remove_$key")) {
                $brandAssets->delete(Settings::get($key));
                $values[$key] = '';
            }
        }
        if ($request->boolean('mascot_sent')) {
            $values['menu_header_brand'] = $request->boolean('menu_header_brand') ? '1' : '0';
        }
        Settings::put($values);
        Audit::record('settings.updated', '', ['keys' => array_values(array_diff(array_keys($values), ['ai_key']))]);

        // back on the tab the owner saved from
        $tab = $request->input('settings_tab');

        return (in_array($tab, self::TABS, true) ? redirect()->route('dash.settings', ['tab' => $tab]) : back())->with('ok', __('Settings saved.'));
    }

    /** One tiny request with the saved key and model, so the owner knows the import will work. */
    public function testAi(): RedirectResponse
    {
        try {
            AiProviders::make()->ping();
        } catch (AiException|\RuntimeException $e) {
            return back()->withErrors(['ai_key' => $e->getMessage()]);
        }

        return back()->with('ok', __('The AI key works.'));
    }
}
