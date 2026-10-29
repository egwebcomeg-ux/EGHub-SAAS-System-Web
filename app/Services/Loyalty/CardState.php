<?php

namespace App\Services\Loyalty;

use App\Models\Coupon;
use App\Models\Customer;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Customers\GuestAccount;
use App\Services\Entitlements;
use App\Services\Settings;
use App\Services\Wallet\WalletService;

/**
 * What one guest's card shows right now (stamps or points, rewards ready, prepaid balance, tier), reduced to plain
 * values: the card app re-reads it from /card/{token}/state and Apple / Google Wallet passes are filled from it.
 * Nothing personal beyond the name the guest gave; never the mobile number.
 */
final class CardState
{
    public function __construct(private LoyaltyService $loyalty, private Membership $membership) {}

    /**
     * Null when the restaurant has nothing to show on a card (same rule as the card page: 404).
     *
     * @return array{restaurant:string, brand:string, title:string, name:string, url:string, color:string, type:?string, stamps:?int, required:?int, points:?int, rewards:list<string>, balance:?float, currency:string, tier:?string, packages:int, vouchers:int, version:string}|null
     */
    public function for(Customer $customer): ?array
    {
        $s = Settings::all();
        $program = $this->loyalty->program();
        $card = $program ? $this->loyalty->card($program, $customer) : null;
        $points = $program?->type === 'points';
        $balance = WalletService::enabled() ? round((float) $customer->credit_balance, 2) : null;
        $packages = Packages::enabled() ? app(Packages::class)->activeFor($customer)->count() : 0;
        $vouchers = (Entitlements::current()->allows('loyalty_stamps') ? Coupon::heldBy($customer)->count() : 0)
            + (CorporateVouchers::enabled() ? app(CorporateVouchers::class)->heldBy($customer)->count() : 0);
        if (! $program && $balance === null && $packages === 0 && $vouchers === 0 && ! Membership::enabled()) {
            return null;
        }
        $design = $program->card_design ?? [];
        $brand = trim(tr($s['brand_name'], $s['brand_name_en'])) ?: (string) tenant()->name;

        $state = [
            'restaurant' => (string) tenant()->getTenantKey(),
            'brand' => $brand,
            'title' => $program ? (string) tr($program->title, $program->title_en) : __('My card'),
            'name' => (string) $customer->name,
            'url' => route('menu.card', $customer->card_token),
            // what the QR carries: the counter finds the guest by it, it opens nothing of theirs (the url is their key)
            'scan_url' => GuestAccount::scanUrl($customer),
            'color' => is_hex_color($design['bg'] ?? null) ? $design['bg'] : (is_hex_color($s['color_secondary']) ? $s['color_secondary'] : '#B5451B'),
            'type' => $program ? ($points ? 'points' : 'stamps') : null,
            'stamps' => $card && ! $points ? (int) $card->stamps : null,
            'required' => $program && ! $points ? (int) $program->stamps_required : null,
            'points' => $card && $points ? (int) $card->stamps : null,
            'rewards' => $card ? $card->availableRewards()->pluck('label')->map(fn ($l) => (string) $l)->all() : [],
            'balance' => $balance,
            'currency' => (string) tr($s['currency'], $s['currency_en']),
            'tier' => $this->membership->tierFor($customer)?->displayName(),
            'packages' => $packages,
            'vouchers' => $vouchers,
        ];

        return $state + ['version' => substr(md5((string) json_encode($state)), 0, 16)];
    }
}
