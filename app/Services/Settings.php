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
        'color_primary' => '#E8642C',
        'color_secondary' => '#1E2D4F',
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
        'online_prep_minutes' => '20',
        'online_auto_cancel_minutes' => '0',  // 0 = off; else online orders nobody accepted in N minutes are cancelled (egmenu:auto-cancel)
        // busy mode (orders board): until this moment online/takeaway/delivery ordering is paused; table orders go on
        'busy_until' => '',                   // ISO datetime or empty
        'busy_message' => '',
        'service_percent' => '0',
        'tax_percent' => '0',
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
