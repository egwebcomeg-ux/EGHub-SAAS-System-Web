<?php

namespace App\Services;

use App\Models\TenantSetting;

/**
 * Key/value settings of the current restaurant with their defaults. Read once per request and memoised per tenant.
 */
final class Settings
{
    public const DEFAULTS = [
        // brand
        'brand_name' => '',
        'brand_name_en' => '',
        'tagline' => '',
        'tagline_en' => '',
        'logo' => '',
        'cover' => '',
        'currency' => 'ج.م',
        'currency_en' => 'EGP',
        // look
        'color_primary' => '#F26522',
        'color_secondary' => '#0A2540',
        'color_bg' => '#FFFFFF',
        'font' => 'Cairo',
        'menu_theme' => 'classic',
        'business_type' => 'cafe',          // cafe | restaurant | both | shop (App\Support\BusinessType)
        'default_locale' => 'ar',
        'english_enabled' => '1',
        // contact
        'phone' => '',
        'whatsapp' => '',
        'instagram' => '',
        'facebook' => '',
        'tiktok' => '',
        'google_review_url' => '',
        // showing up in search: profile page egmenu.app/{slug}/about and the directory egmenu.app/restaurants
        'city' => '',                       // App\Support\Cities slug
        'area' => '',                       // free text, e.g. المعادي
        'cuisine' => '',                    // App\Support\Cuisines keys, comma-separated
        'short_description' => '',
        'short_description_en' => '',
        'listed_in_directory' => '1',
        // what the table and storefront QR open: the menu, or the links page egmenu.app/{slug}/links
        'qr_target' => 'menu',
        // printed table QR cards / stands (App\Support\StandStyles): material look, print layout, optional lines (JSON)
        'stand_style' => 'white',           // white | navy | wood | frosted
        'stand_layout' => 'classic',        // classic (9 small cards per A4) | a6 (one per page) | a4 (four A6 per A4)
        'stand_options' => '',              // json {accent, waiter, payment, wifi_name, wifi_password, url}
        // ad tracking pixels on the public menu pages (feature offers|full_branding, App\Services\Marketing\TrackingPixels)
        'meta_pixel_id' => '',
        'ga4_measurement_id' => '',
        'tiktok_pixel_id' => '',
        // ordering
        'ordering_enabled' => '1',
        'whatsapp_ordering' => '0',
        // online ordering from the menu link (plan feature online_ordering)
        'online_takeaway' => '1',
        'online_delivery' => '0',
        'delivery_zone_mode' => 'manual',   // manual (main area → sub-areas/streets, fees typed) | map (areas drawn on the map)
        'online_prep_minutes' => '20',
        'online_auto_cancel_minutes' => '0',  // 0 = off; else online orders nobody accepted in N minutes are cancelled (egmenu:auto-cancel)
        // busy mode (orders board): until this moment online/takeaway/delivery ordering is paused; table orders go on
        'busy_until' => '',                   // ISO datetime or empty
        'busy_message' => '',
        // the hall (App\Support\DineIn): order (send from the table) | list (show the waiter) | off; empty = order with
        // table ordering in the plan and ordering on, else list
        'dine_in_mode' => '',
        'dine_in_show_prices' => '',          // '' = shown in an order, hidden on a list; '1' | '0' when the owner chose
        'dine_in_notice' => '',               // text on the hall list; empty = the default sentence
        // general menu link: "how will you order?" screen when the hall and takeaway/delivery are both offered
        'order_type_prompt' => '1',
        // branded splash on the first open per session (App\Support\MenuIntro): off | logo | full
        'menu_intro' => 'logo',
        'menu_intro_repeat' => 'always',  // always (every time the menu is opened) | session (once per browser session)
        // behind the logo on the guest pages: auto (no tile on dark templates, white on light ones) | white | none
        'logo_background' => 'auto',
        // items without a photo show a themed placeholder tile (icon from the category) instead of nothing
        'item_placeholder' => '1',
        // item layout apart from the theme colors (App\Support\MenuLayouts): auto (the theme's own) | list | grid | cards | text
        'menu_layout' => 'auto',
        // tapping an item: simple (the bottom sheet) | pro (a full item page with its own link; plan feature menu_themes)
        'menu_display' => 'simple',
        // the category row of the menu: chips (rounded text buttons, the default for every restaurant) | circles
        // (a round photo or icon per category with its name under it)
        'menu_category_style' => 'chips',
        // "الأكثر طلباً" on top of the menu from real sales of the branch (App\Services\Menu\BestSellers); '0' = off
        'menu_best_sellers' => '1',
        // badges shown as quick filter chips on the menu (App\Support\ItemBadges): JSON list of keys, empty = off
        'menu_badge_filters' => '',
        // the banner strip plays as a slideshow (2+ banners); the ticker then stands still (App\Support\MenuTicker)
        'banner_autoplay' => '1',
        // the owner's own ticker lines under the menu header: JSON [{text, text_en, link: "item:12"|"category:3"|""}], up to 5
        'menu_ticker_lines' => '',
        // cart goal of the public menu (feature offers, App\Services\Orders\CartGoal): "add X more and get Y free"
        'cart_goal_enabled' => '0',
        'cart_goal_amount' => '0',            // threshold on the order's food, in the restaurant's currency
        'cart_goal_reward' => 'item',         // item (free item) | percent | amount (off the order)
        'cart_goal_item' => '',               // menu item id given free
        'cart_goal_value' => '0',             // percent or amount off
        'cart_goal_label' => '',              // empty = generated ("Order for 200 EGP and get a croissant free")
        'cart_goal_with_coupon' => '0',       // '1' = also given when the guest uses a coupon
        'service_percent' => '0',
        'tax_percent' => '0',
        // POS offline mode: hours a device may keep selling without internet before it must sync (App\Services\Orders\Terminals)
        'pos_offline_hours' => '12',
        // AI menu import with the restaurant's own key (ai_key is encrypted, see AiProviders)
        'ai_provider' => '',
        'ai_model' => '',
        'ai_key' => '',
        // WhatsApp messages to guests (feature whatsapp_notifications; each message costs one credit)
        'wa_order_received' => '0',
        'wa_order_ready' => '0',
        'wa_order_on_way' => '0',
        'wa_reservation_confirmed' => '0',
        'wa_loyalty_reward' => '0',
        'wa_order_to_staff' => '0',         // new table/online/kiosk orders also go to the branch's own WhatsApp number
        'wa_driver_assigned' => '0',        // a delivery order given to a driver: a WhatsApp to the driver's own number
        'wa_sender_name' => '',
        // cashback (feature cashback): this share of every counted order goes back to the guest's wallet balance
        'cashback_percent' => '0',
        'cashback_min_order' => '0',
        'cashback_expire_days' => '0',      // 0 = cashback never expires; else unspent cashback is taken back after N days
        // extra menu languages (feature extra_languages): JSON list of App\Support\MenuLocales codes
        'menu_locales' => '[]',
        // progress of the AI translation of the whole menu (App\Jobs\TranslateMenu), JSON
        'menu_translation_status' => '',
        // owners get the week's numbers by email on Monday morning (feature advanced_reports)
        'weekly_report_email' => '0',
        'weekly_report_sent_for' => '', // Monday of the last week emailed, so a second run the same day sends nothing
        // table reservations (plan feature reservations, App\Services\Reservations\ReservationService)
        'reservations_enabled' => '0',
        'reservation_auto_confirm' => '0',
        'reservation_slot_minutes' => '30',
        'reservation_duration_minutes' => '90',  // how long a table is held; overlapping bookings share the seats
        'reservation_max_party' => '12',
        'reservation_lead_hours' => '1',
        'reservation_horizon_days' => '30',
        'reservation_hours' => '',               // json {0..6 (Sunday = 0): {open, close}}; empty = the branch working hours
        'reservation_seats' => '',               // json {branch id: seats per slot}; missing = 20
        // security: the owner can require two-factor authentication of every staff member (App\Services\Auth\TwoFactor)
        'require_2fa' => '0',
        // onboarding checklist on the dashboard home (App\Services\Onboarding)
        'qr_printed_at' => '',                   // set when the QR cards print page is opened
        'ordering_decided' => '0',               // the settings form was saved at least once (ordering on/off chosen)
        'onboarding' => '',                      // json {skipped: [step keys], dismissed: bool}
        // "what will you use?" (App\Services\ModuleChoice): json list of App\Support\SiteModules slugs; '' = not picked
        'modules' => '',
    ];

    /** @var array<string,array<string,string>> */
    private static array $memo = [];

    public static function get(string $key, ?string $default = null): string
    {
        return self::all()[$key] ?? $default ?? (self::DEFAULTS[$key] ?? '');
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        $id = (string) tenant()?->getTenantKey();

        return self::$memo[$id] ??= array_merge(self::DEFAULTS,
            TenantSetting::query()->pluck('value', 'key')->map(fn ($v) => (string) $v)->all());
    }

    /** @param array<string,string|null> $values */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            TenantSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        self::flush();
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
