<?php

namespace App\Services\Crm;

use App\Models\AddonPrice;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Lead;
use App\Models\MenuImport;
use App\Models\MenuItem;
use App\Models\MenuVisit;
use App\Models\Order;
use App\Models\ServiceRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MenuSetupRequest;
use App\Services\Settings;
use App\Support\BusinessType;
use App\Support\Cities;

/**
 * What a new restaurant actually did since it signed up, read from its real rows and settings (never guessed), so the
 * caller knows where it got stuck before saying hello: came back after sign-up, items and photos, logo and colors,
 * menu imports, QR cards printed, visits and scans, orders, tables, staff, add-ons, custom domain, phone confirmed.
 * Read inside the restaurant's tenant context, like the admin's restaurant page.
 */
final class LeadSignals
{
    /** Signing in again this long after the account was made counts as "came back". */
    private const CAME_BACK_AFTER_MINUTES = 60;

    /**
     * @return array{owner: ?User, type: string, city: string, came_back: bool, engaged: bool, items: int, stuck: string,
     *     rows: list<array{key: string, label: string, value: string, state: string}>}
     */
    public function read(Tenant $tenant): array
    {
        $tenant->loadMissing('subscription.addons', 'domains');
        tenancy()->initialize($tenant);
        try {
            $s = Settings::all();
            $d = Settings::DEFAULTS;
            $users = User::query()->with('roles')->get();
            $owner = $users->first(fn (User $u) => $u->isOwner()) ?? $users->first();
            $lastLogin = $users->max('last_login_at');
            $cameBack = $lastLogin !== null && $tenant->created_at !== null
                && $lastLogin->gt($tenant->created_at->copy()->addMinutes(self::CAME_BACK_AFTER_MINUTES));
            $items = MenuItem::query()->count();
            $withPhotos = MenuItem::query()->whereNotNull('image')->where('image', '!=', '')->count();
            $categories = Category::query()->count();
            $logo = trim((string) $s['logo']) !== '';
            $colors = $s['color_primary'] !== $d['color_primary'] || $s['color_secondary'] !== $d['color_secondary'] || $s['color_bg'] !== $d['color_bg'];
            $imports = MenuImport::query()->get(['source', 'status']);
            $aiImports = $imports->whereIn('source', ['image', 'pdf']);
            $qrPrinted = trim((string) $s['qr_printed_at']);
            $views = (int) MenuVisit::query()->sum('views');
            $scans = (int) MenuVisit::query()->sum('scans');
            $weekViews = (int) MenuVisit::query()->where('day', '>=', local_at()->subDays(6)->toDateString())->sum('views');
            $orders = Order::query()->count();
            $lastOrder = $orders > 0 ? Order::query()->max('created_at') : null;
            $tables = DiningTable::query()->count();
            $type = isset(BusinessType::TYPES[$s['business_type'] ?? '']) ? (string) $s['business_type'] : BusinessType::DEFAULT;
            $city = (string) ($s['city'] ?? '');
        } finally {
            tenancy()->end();
            Settings::flush();
        }
        $phone = (string) ($owner?->phone ?? '');
        $setupAsked = $phone !== '' && ServiceRequest::query()->where('service', MenuSetupRequest::SERVICE)->where('phone', $phone)->exists();
        $addons = $tenant->subscription?->addons->where('quantity', '>', 0) ?? collect();
        $names = AddonPrice::query()->whereIn('key', $addons->pluck('key'))->get()->mapWithKeys(fn (AddonPrice $p) => [$p->key => $p->displayName()]);
        $domain = $tenant->domains->first();
        $none = __('Nothing yet');

        $rows = [
            $this->row('came_back', __('Came back after signing up'), $cameBack
                ? __('Yes, last sign-in :at', ['at' => local_time($lastLogin)]) : ($lastLogin ? __('No, only at sign-up') : __('Never signed in')), $cameBack ? 'ok' : 'warn'),
            $this->row('items', __('Menu items'), $items > 0 ? __(':n items, :p with a photo', ['n' => $items, 'p' => $withPhotos]) : $none, $items > 0 ? 'ok' : 'warn'),
            $this->row('categories', __('Categories'), __(':n (the starter ones are made at sign-up)', ['n' => $categories]), $categories > 0 ? 'ok' : 'none'),
            $this->row('brand', __('Logo and colors'), match (true) {
                $logo && $colors => __('Logo and colors set'), $logo => __('Logo only'), $colors => __('Colors only'), default => $none,
            }, $logo ? 'ok' : 'warn'),
            $this->row('imports', __('Menu imports'), $imports->isEmpty() ? $none : __(':n imports (:ai from a photo or PDF), :applied applied', [
                'n' => $imports->count(), 'ai' => $aiImports->count(), 'applied' => $imports->where('status', 'applied')->count(),
            ]), $imports->where('status', 'applied')->isNotEmpty() ? 'ok' : 'none'),
            $this->row('menu_setup', __('Asked our team to enter the menu'), $setupAsked ? __('Yes, see service requests') : __('No'), $setupAsked ? 'ok' : 'none'),
            $this->row('qr', __('QR cards print page opened'), $qrPrinted !== '' ? local_time($qrPrinted) : $none, $qrPrinted !== '' ? 'ok' : 'warn'),
            $this->row('visits', __('Menu visits'), $views + $scans > 0
                ? __(':views views, :scans table scans (:week views in the last 7 days)', ['views' => number_format($views), 'scans' => number_format($scans), 'week' => number_format($weekViews)]) : $none,
                $views + $scans > 0 ? 'ok' : 'warn'),
            $this->row('orders', __('Orders received'), $orders > 0 ? __(':n orders, last :at', ['n' => number_format($orders), 'at' => local_time($lastOrder)]) : $none, $orders > 0 ? 'ok' : 'none'),
            $this->row('tables', __('Tables'), (string) $tables, $tables > 0 ? 'ok' : 'none'),
            $this->row('staff', __('Staff accounts'), (string) $users->count(), $users->count() > 1 ? 'ok' : 'none'),
            $this->row('addons', __('Add-ons'), $addons->isEmpty() ? $none : $addons->map(fn ($a) => ($names[$a->key] ?? $a->key).($a->quantity > 1 ? ' × '.$a->quantity : ''))->implode('، '), $addons->isEmpty() ? 'none' : 'ok'),
            $this->row('domain', __('Custom domain'), $domain ? $domain->domain.' · '.$domain->statusLabel() : $none, $domain?->isActive() ? 'ok' : 'none'),
            $this->row('phone', __('Mobile confirmed on WhatsApp'), $owner?->hasVerifiedPhone() ? __('Yes') : __('No'), $owner?->hasVerifiedPhone() ? 'ok' : 'none'),
        ];

        return [
            'owner' => $owner,
            'type' => $type,
            'city' => isset(Cities::ALL[$city]) ? Lead::pick(Cities::ALL[$city]) : '',
            'came_back' => $cameBack,
            'engaged' => $cameBack || $items > 0 || $orders > 0,
            'items' => $items,
            'stuck' => $this->stuck($cameBack, $lastLogin !== null, $items, $logo, $qrPrinted !== '', $views + $scans, $orders, $setupAsked),
            'rows' => $rows,
        ];
    }

    /** Where the restaurant stopped, as one line for the caller. */
    private function stuck(bool $cameBack, bool $everIn, int $items, bool $logo, bool $qr, int $visits, int $orders, bool $setupAsked): string
    {
        return match (true) {
            ! $everIn => __('Never opened the dashboard: start with what EgHub is and send the sign-in link on WhatsApp.'),
            $items === 0 && $setupAsked => __('Asked our team to enter the menu: check the service request before calling.'),
            $items === 0 && ! $cameBack => __('Signed up and never came back, no items yet: they may not know what they signed up for.'),
            $items === 0 => __('Came back but added no items: help with the menu (Excel import, or our team enters it).'),
            ! $logo => __('Items are in, no logo yet: the menu does not look like their place.'),
            ! $qr => __('The menu is ready but the QR cards were never printed: guests cannot reach it yet.'),
            $visits === 0 => __('QR cards printed but no visits yet: ask whether the cards are on the tables.'),
            $orders === 0 => __('Guests visit the menu but no orders: ask whether they want table or online ordering.'),
            default => __('Using it: receiving orders. Talk about the plan before the trial ends.'),
        };
    }

    /** @return array{key: string, label: string, value: string, state: string} */
    private function row(string $key, string $label, string $value, string $state): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'state' => $state];
    }
}
