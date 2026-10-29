<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\GiftCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\MembershipTier;
use App\Models\MenuItem;
use App\Models\PrepaidPackage;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Entitlements;
use App\Services\Loyalty\Membership;
use App\Services\Marketing\Automations;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use App\Support\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/**
 * «المكافآت»: one settings page for every reward a guest can get — the stamps / points program, the welcome for new
 * guests (the gift on the first online order next to the thank-you message after the first order), cashback,
 * membership tiers, prepaid packages and the wallet. Each section shows only with its plan feature and permission;
 * the forms post to the endpoints they always did (LoyaltyController, CardsController, AutomationsController).
 */
class RewardsController extends Controller
{
    /** Section key => its anchor on the page, in page order. The old setup pages redirect to theirs. */
    public const SECTIONS = ['program', 'welcome', 'cashback', 'tiers', 'packages', 'wallet'];

    public function index(Request $request, Automations $automations): View
    {
        $sections = self::sections($request->user());
        abort_if(! in_array(true, $sections, true), 403);
        $welcome = self::welcomeParts($request->user());

        return view('dashboard.loyalty.rewards', [
            'sections' => $sections,
            'welcomeParts' => $welcome,
            'points' => Entitlements::current()->allows('loyalty_points'),
            'settings' => Settings::all(),
        ]
            + ($sections['program'] ? $this->program() : [])
            + ($welcome['automation'] ? $this->welcomeAutomation($automations) : [])
            + ($sections['cashback'] ? $this->cashback() : [])
            + ($sections['tiers'] ? ['tiers' => MembershipTier::query()->orderBy('min_spent')->orderBy('min_visits')->orderBy('sort_order')->get(), 'members' => $this->memberCounts()] : [])
            + ($sections['packages'] ? $this->packages() : [])
            + ($sections['wallet'] ? $this->wallet() : []));
    }

    /**
     * Which sections this user sees: the plan feature and the permission of each.
     *
     * @return array{program: bool, welcome: bool, cashback: bool, tiers: bool, packages: bool, wallet: bool}
     */
    public static function sections(User $user): array
    {
        $rights = Entitlements::current();
        $manages = $user->can('loyalty.manage');
        $welcome = self::welcomeParts($user);

        return [
            'program' => $manages && $rights->allows('loyalty_stamps'),
            'welcome' => $welcome['gift'] || $welcome['automation'],
            'cashback' => $manages && $rights->allows('cashback'),
            'tiers' => $manages && $rights->allows('membership'),
            'packages' => $manages && $rights->allows('prepaid_packages'),
            'wallet' => $user->can('wallet.manage') && WalletService::enabled(),
        ];
    }

    /**
     * Whether the user sets any reward here, for the links from the guest desk and the wallet page (the wallet section
     * alone only repeats the wallet page's totals).
     */
    public static function managesRewards(User $user): bool
    {
        return in_array(true, Arr::except(self::sections($user), 'wallet'), true);
    }

    /**
     * The two ways to welcome a new guest: the gift on the first online order (loyalty card) and the WhatsApp thank-you
     * after the first order (campaigns).
     *
     * @return array{gift: bool, automation: bool}
     */
    public static function welcomeParts(User $user): array
    {
        $rights = Entitlements::current();

        return [
            'gift' => $user->can('loyalty.manage') && $rights->allows('loyalty_stamps'),
            'automation' => $user->can('marketing.manage') && $rights->allows('campaigns'),
        ];
    }

    /** The first section of $keys this user sees (the anchor an old page redirects to), or the first one shown at all. */
    public static function anchorFor(User $user, string ...$keys): string
    {
        $sections = self::sections($user);
        foreach ([...$keys, ...self::SECTIONS] as $key) {
            if ($sections[$key] ?? false) {
                return $key;
            }
        }

        return $keys[0] ?? self::SECTIONS[0];
    }

    /** @return array<string, mixed> the stamps / points program, with the business type's defaults before the first save */
    private function program(): array
    {
        // the type's defaults (a shop starts on points) win, unless the plan has no points programs
        $defaults = BusinessType::loyaltyDefaults();
        if (($defaults['type'] ?? 'visits') === 'points' && ! Entitlements::current()->allows('loyalty_points')) {
            unset($defaults['type']);
        }
        $program = LoyaltyProgram::query()->latest('id')->first() ?? new LoyaltyProgram($defaults + [
            'type' => 'visits', 'title' => __('Loyalty card'), 'reward_type' => 'free_item', 'is_active' => true,
        ]);

        return [
            'program' => $program,
            'locked' => $program->isLocked(),
            'categories' => Category::query()->orderBy('sort_order')->get(),
            'items' => MenuItem::query()->orderBy('name')->get(['id', 'name', 'name_en', 'category_id']),
            'icons' => LoyaltyController::STAMP_ICONS,
            'stats' => $program->exists ? [
                'cards' => $program->cards()->count(),
                'rewards' => LoyaltyReward::query()->whereIn('loyalty_card_id', $program->cards()->select('id'))->count(),
                'redeemed' => LoyaltyReward::query()->whereIn('loyalty_card_id', $program->cards()->select('id'))->where('status', 'redeemed')->count(),
            ] : null,
        ];
    }

    /** @return array<string, mixed> the welcome automation of the campaigns page, with what it brought in */
    private function welcomeAutomation(Automations $automations): array
    {
        $automation = $automations->all()->get('welcome');

        return [
            'automation' => $automation,
            'automationResults' => $automations->results($automation),
            'coupons' => Coupon::query()->where('is_active', true)->whereNull('customer_id')->orderBy('code')->get(),
            'menuUrl' => tenant()->url(),
            'whatsappBalance' => $automations->balance(),
        ];
    }

    /** @return array<string, mixed> */
    private function cashback(): array
    {
        return [
            'wallet' => WalletService::enabled(),
            'cashback' => ['percent' => Settings::get('cashback_percent'), 'min_order' => Settings::get('cashback_min_order'), 'expire_days' => Settings::get('cashback_expire_days'),
                'month' => round((float) WalletTransaction::query()->where('kind', 'cashback')->where('created_at', '>=', now()->startOfMonth())->sum('delta'), 2)],
        ];
    }

    /** @return array<string, mixed> */
    private function packages(): array
    {
        return [
            'packages' => PrepaidPackage::query()->withCount(['sold', 'sold as active_count' => fn ($q) => $q->where('status', 'active')])->orderBy('sort_order')->orderBy('id')->get(),
            'packageCategories' => Category::query()->orderBy('sort_order')->get(['id', 'name', 'name_en']),
            'packageItems' => MenuItem::query()->orderBy('name')->get(['id', 'name', 'name_en', 'category_id']),
            'recent' => CustomerPackage::query()->with(['customer', 'seller'])->latest('id')->limit(15)->get(),
        ];
    }

    /** @return array<string, mixed> what the wallet holds today; the balances, ledger and gift cards stay on the wallet page */
    private function wallet(): array
    {
        return [
            'walletTotals' => [
                'liability' => round((float) Customer::query()->sum('credit_balance'), 2),
                'gifts' => round((float) GiftCard::query()->where('status', 'active')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->sum('balance'), 2),
            ],
        ];
    }

    /** @return array<int, int> tier id => how many guests are on it today */
    private function memberCounts(): array
    {
        $membership = app(Membership::class);
        $counts = [];
        Customer::query()->where(fn ($q) => $q->where('visits_count', '>', 0)->orWhere('total_spent', '>', 0))
            ->select(['id', 'visits_count', 'total_spent'])->chunk(500, function ($customers) use ($membership, &$counts) {
                foreach ($customers as $c) {
                    if ($tier = $membership->tierFor($c)) {
                        $counts[$tier->id] = ($counts[$tier->id] ?? 0) + 1;
                    }
                }
            });

        return $counts;
    }
}
