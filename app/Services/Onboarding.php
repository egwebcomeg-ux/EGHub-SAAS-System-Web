<?php

namespace App\Services;

use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\Ingredient;
use App\Models\KbArticle;
use App\Models\Kiosk;
use App\Models\LoyaltyProgram;
use App\Models\MenuItem;
use App\Models\PosTerminal;
use App\Models\TvScreen;
use App\Models\User;

/**
 * The "get your menu ready" checklist on the dashboard home, for owners and managers. Every step is read from
 * real data (no flags to forget), except what only the owner can tell us: ordering decided (the settings form
 * saved once), the QR cards printed (print page opened), and the steps they chose to skip.
 * State lives in the `onboarding` setting as JSON {skipped: [...], dismissed: bool}.
 * Once the owner picked the modules they use (App\Services\ModuleChoice), module steps follow that pick: ordering and
 * loyalty only when picked, plus a setup step for picked modules such as reservations, delivery areas or the kiosk.
 */
final class Onboarding
{
    public const STEPS = ['brand', 'menu', 'tables', 'ordering', 'staff', 'loyalty', 'reservations', 'zones', 'drivers', 'inventory', 'terminals', 'tv', 'kiosk', 'kb', 'whatsapp', 'plan'];

    /** Steps that belong to modules: step => the picked modules any of which keeps it in the checklist. */
    public const MODULE_STEPS = [
        'ordering' => ['table-ordering', 'online-ordering'],
        'loyalty' => ['loyalty'],
        'reservations' => ['reservations'],
        'zones' => ['online-ordering', 'call-center'],
        'drivers' => ['online-ordering', 'call-center', 'delivery-drivers'],
        'inventory' => ['inventory'],
        'terminals' => ['pos'],
        'tv' => ['tv-screens'],
        'kiosk' => ['kiosk'],
        'kb' => ['knowledge-base'],
    ];

    /** Setup steps that only appear once their module was picked; without a pick the checklist stays as it was. */
    private const PICKED_ONLY = ['reservations', 'zones', 'drivers', 'inventory', 'terminals', 'tv', 'kiosk', 'kb'];

    public const MENU_ITEMS_WITH_PHOTOS = 5;

    public static function visibleTo(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'manager']);
    }

    /**
     * @return array{steps: list<array{key:string, title:string, text:string, done:bool, skipped:bool, url:?string}>,
     *     done:int, total:int, percent:int, hidden:bool}
     */
    public static function status(User $user): array
    {
        $state = self::state();
        $rights = Entitlements::current();
        $s = Settings::all();
        $d = Settings::DEFAULTS;
        $link = fn (string $permission, string $url) => $user->can($permission) ? $url : null;

        // branding colors can only change with the full_branding feature; without it, the logo alone counts
        $colorsChanged = $s['color_primary'] !== $d['color_primary'] || $s['color_secondary'] !== $d['color_secondary'] || $s['color_bg'] !== $d['color_bg'];
        $withPhotos = MenuItem::query()->whereNotNull('image')->where('image', '!=', '')->count();
        $subscription = tenant()->subscription;

        $steps = [
            self::step('brand', __('Add your logo and colors'), __('Your logo and brand colors show on the menu and the QR cards.'),
                $s['logo'] !== '' && (! $rights->allows('full_branding') || $colorsChanged), $link('settings.manage', route('dash.settings'))),
            self::step('menu', __('Add at least :n items with photos', ['n' => self::MENU_ITEMS_WITH_PHOTOS]),
                __(':n of :total items have a photo so far. Photos sell.', ['n' => min($withPhotos, self::MENU_ITEMS_WITH_PHOTOS), 'total' => self::MENU_ITEMS_WITH_PHOTOS]),
                $withPhotos >= self::MENU_ITEMS_WITH_PHOTOS, $link('menu.manage', route('dash.menu'))),
            self::step('tables', __('Print the QR cards for your tables'), __('Add your tables, then open the print page from Tables & QR.'),
                $s['qr_printed_at'] !== '' && DiningTable::query()->exists(), $link('tables.manage', route('dash.tables'))),
            self::step('ordering', __('Decide whether guests order from the QR'), __('Turn ordering from the table on or off in Settings and save.'),
                $s['ordering_decided'] === '1', $link('settings.manage', route('dash.settings').'#ordering')),
            self::step('staff', __('Invite your team'), __('A cashier, a manager or the kitchen, each with their own login and permissions.'),
                User::query()->count() >= 2, $link('users.manage', route('dash.users'))),
        ];
        if ($rights->allows('loyalty_stamps')) {
            $steps[] = self::step('loyalty', __('Set up the loyalty card'), __('Stamps or points that bring guests back.'),
                LoyaltyProgram::query()->where('is_active', true)->exists(), $link('loyalty.manage', route('dash.loyalty.setup')));
        }
        if (self::follows('reservations') && $rights->allows('reservations')) {
            $steps[] = self::step('reservations', __('Open table reservations'), __('Set your hours and seats, then turn bookings on.'),
                $s['reservations_enabled'] === '1', $link('reservations.manage', route('dash.reservations')));
        }
        if (self::follows('zones') && $rights->allows('delivery_zones|online_ordering')) {
            $steps[] = self::step('zones', __('Add your delivery areas'), __('Each area with its delivery fee, so guests see the right total.'),
                DeliveryZone::query()->exists(), $link('delivery.manage', route('dash.zones')));
        }
        if (self::follows('drivers') && $rights->allows('delivery_zones|online_ordering')) {
            $steps[] = self::step('drivers', __('Add a driver'), __('A rider with the driver role gets their deliveries on their phone, and guests follow the ride live.'),
                User::role('driver')->exists(), $link('users.manage', route('dash.users')));
        }
        if (self::follows('inventory') && $rights->allows('inventory')) {
            $steps[] = self::step('inventory', __('Add your ingredients'), __('Add the ingredients and their stock, then link them to your recipes.'),
                Ingredient::query()->exists(), $link('inventory.manage', route('dash.inventory')));
        }
        if (self::follows('terminals') && $rights->allows('pos')) {
            $steps[] = self::step('terminals', __('Name your POS terminals'), __('“Cashier 1”, “K2”: each till gets a name and a code, so shifts, sales and offline numbers say where they came from.'),
                PosTerminal::query()->exists(), $link('branches.manage', route('dash.terminals')));
        }
        if (self::follows('tv') && $rights->allows('tv_screens')) {
            $steps[] = self::step('tv', __('Set up your first TV screen'), __('Create a screen and open its link on the TV browser.'),
                TvScreen::query()->exists(), $link('menu.manage', route('dash.devices', ['tab' => 'tv'])));
        }
        if (self::follows('kiosk') && $rights->allows('kiosk')) {
            $steps[] = self::step('kiosk', __('Set up the kiosk tablet'), __('Create a kiosk and open its link on the tablet at the counter.'),
                Kiosk::query()->exists(), $link('kiosk.manage', route('dash.devices', ['tab' => 'kiosk'])));
        }
        if (self::follows('kb') && $rights->allows('knowledge_base')) {
            $steps[] = self::step('kb', __('Write the answers your team gives every day'), __('Opening hours, delivery areas, parking, payment: the call center finds them in a second, and the public ones show to guests on the menu.'),
                KbArticle::query()->where('is_active', true)->exists(), $link('kb.manage', route('dash.kb')));
        }
        $steps = array_values(array_filter($steps, fn (array $st) => self::follows($st['key'])));
        $steps[] = self::step('whatsapp', __('Confirm your WhatsApp number'), __('One ready message from your phone, no codes to type.'),
            $user->hasVerifiedPhone() || User::query()->whereNotNull('phone_verified_at')->exists(), route('dash.phone'));
        $steps[] = self::step('plan', __('Choose your plan'), __('Pick the plan that fits and pay by InstaPay or Vodafone Cash.'),
            $subscription !== null && $subscription->status !== 'trialing', $link('billing.manage', route('dash.billing')));

        foreach ($steps as &$step) {
            $step['skipped'] = ! $step['done'] && in_array($step['key'], $state['skipped'], true);
        }
        unset($step);

        $total = count($steps);
        $done = count(array_filter($steps, fn (array $st) => $st['done'] || $st['skipped']));
        $percent = (int) round($done * 100 / max(1, $total));

        return ['steps' => $steps, 'done' => $done, 'total' => $total, 'percent' => $percent, 'hidden' => $state['dismissed'] || $percent >= 100];
    }

    public static function skip(string $key): void
    {
        $state = self::state();
        if (in_array($key, self::STEPS, true) && ! in_array($key, $state['skipped'], true)) {
            $state['skipped'][] = $key;
            self::save($state);
        }
    }

    public static function dismiss(): void
    {
        self::save(['dismissed' => true] + self::state());
    }

    /** Called when the QR cards print page opens: the "tables & QR" step is then about done. */
    public static function markQrPrinted(): void
    {
        if (Settings::get('qr_printed_at') === '') {
            Settings::put(['qr_printed_at' => now()->toIso8601String()]);
        }
    }

    /** @return array{skipped: list<string>, dismissed: bool} */
    public static function state(): array
    {
        $raw = json_decode(Settings::get('onboarding') ?: '{}', true);
        $raw = is_array($raw) ? $raw : [];

        return [
            'skipped' => array_values(array_filter((array) ($raw['skipped'] ?? []), 'is_string')),
            'dismissed' => (bool) ($raw['dismissed'] ?? false),
        ];
    }

    /** Whether a step fits the modules the owner picked (no pick yet: the original checklist). */
    private static function follows(string $key): bool
    {
        if (! isset(self::MODULE_STEPS[$key])) {
            return true;
        }
        $chosen = ModuleChoice::chosen();
        if ($chosen === null) {
            return ! in_array($key, self::PICKED_ONLY, true);
        }

        return array_intersect(self::MODULE_STEPS[$key], $chosen) !== [];
    }

    /** @return array{key:string, title:string, text:string, done:bool, skipped:bool, url:?string} */
    private static function step(string $key, string $title, string $text, bool $done, ?string $url): array
    {
        return ['key' => $key, 'title' => $title, 'text' => $text, 'done' => $done, 'skipped' => false, 'url' => $url];
    }

    /** @param array{skipped: list<string>, dismissed: bool} $state */
    private static function save(array $state): void
    {
        Settings::put(['onboarding' => json_encode($state)]);
    }
}
