<?php

namespace App\Services;

use App\Models\DiningTable;
use App\Models\LoyaltyProgram;
use App\Models\MenuItem;
use App\Models\User;

/**
 * The "get your menu ready" checklist on the dashboard home, for owners and managers. Every step is read from
 * real data (no flags to forget), except what only the owner can tell us: ordering decided (the settings form
 * saved once), the QR cards printed (print page opened), and the steps they chose to skip.
 * State lives in the `onboarding` setting as JSON {skipped: [...], dismissed: bool}.
 */
final class Onboarding
{
    public const STEPS = ['brand', 'menu', 'tables', 'ordering', 'staff', 'loyalty', 'whatsapp', 'plan'];

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
