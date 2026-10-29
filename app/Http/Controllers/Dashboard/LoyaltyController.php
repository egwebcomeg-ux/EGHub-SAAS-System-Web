<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\PrepaidPackage;
use App\Services\Audit;
use App\Services\Corporate\CorporateVouchers;
use App\Services\Customers\GuestAccount;
use App\Services\Entitlements;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Loyalty\Membership;
use App\Services\Loyalty\Packages;
use App\Services\Marketing\WelcomeReward;
use App\Services\Orders\ShiftService;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Loyalty program setup (owner/manager; the form is a section of the rewards page, RewardsController) and the guest
 * desk: find the guest once by mobile or scanned card, then stamp, redeem rewards, sell packages, top up the wallet,
 * sell a gift card and see their company vouchers (cashier), each panel with its own feature and permission.
 */
class LoyaltyController extends Controller
{
    public const STAMP_ICONS = ['cup', 'bean', 'star', 'heart', 'burger', 'pizza', 'logo'];

    /** The plan features any of which opens the guest desk (each panel on it still checks its own). */
    public const DESK_FEATURES = 'loyalty_stamps|prepaid_packages|membership|cashback|wallet|corporate_vouchers';

    /** The program's settings live on the rewards page: the old link opens its section there. */
    public function edit(): RedirectResponse
    {
        return redirect()->to(route('dash.rewards').'#program');
    }

    /** «سجّل وخد …»: on/off, the kind of gift and its value; the hidden welcome coupon follows (WelcomeReward::sync). */
    public function welcome(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(WelcomeReward::TYPES)],
            'value' => ['required', 'numeric', 'min:1', $request->input('type') === 'percent' ? 'max:100' : 'max:100000'],
        ]);
        if ($data['type'] === 'points' && app(LoyaltyService::class)->program()?->type !== 'points') {
            return back()->withErrors(['type' => __('Points need a points loyalty program. Set one up above, or choose a discount.')]);
        }
        Settings::put([
            'welcome_reward_enabled' => $request->boolean('enabled') ? '1' : '0',
            'welcome_reward_type' => $data['type'],
            'welcome_reward_value' => (string) round((float) $data['value'], 2),
        ]);
        Settings::flush();
        WelcomeReward::sync();
        Audit::record('loyalty.welcome_reward', $data['type'], ['value' => (float) $data['value'], 'enabled' => $request->boolean('enabled')]);

        return redirect()->to(route('dash.rewards').'#welcome')->with('ok', __('Saved.'));
    }

    public function update(Request $request): RedirectResponse
    {
        $program = LoyaltyProgram::query()->latest('id')->first() ?? new LoyaltyProgram;
        $types = Entitlements::current()->allows('loyalty_points') ? ['visits', 'items', 'points'] : ['visits', 'items'];
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'type' => ['required', Rule::in($types)],
            'title' => 'required|string|max:60',
            'title_en' => 'nullable|string|max:60',
            'stamps_required' => 'required|integer|min:2|max:30',
            'min_order' => 'nullable|numeric|min:0',
            'points_per_egp' => 'nullable|numeric|min:0.01|max:100',
            'reward_label' => 'required|string|max:80',
            'stamps_expire_days' => 'nullable|integer|min:7|max:730',
            'categories' => 'array',
            'categories.*' => [Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'items' => 'array',
            'items.*' => [Rule::exists('menu_items', 'id')->where('tenant_id', $tenant)],
            'milestones' => 'array|max:10',
            'milestones.*.at' => 'nullable|integer|min:1|max:100000',
            'milestones.*.label' => 'nullable|string|max:80',
            'design.bg' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'design.stamp_bg' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'design.icon' => ['required', Rule::in(self::STAMP_ICONS)],
        ]);

        $milestones = collect($data['milestones'] ?? [])->filter(fn ($m) => ! empty($m['at']) && ! empty($m['label']))
            ->map(fn ($m) => ['at' => (int) $m['at'], 'type' => 'free_item', 'label' => $m['label']])->values()->all();

        // A card's size and rewards can't change under guests who already hold one.
        if ($program->isLocked() && ((int) $data['stamps_required'] !== (int) $program->stamps_required
            || $data['type'] !== $program->type || $milestones != array_values($program->milestones ?? []))) {
            return back()->withErrors(['locked' => __('Guests already hold cards: the card size, type and rewards can no longer change. Start a new program to change them.')]);
        }

        $program->fill([
            'type' => $data['type'], 'title' => $data['title'], 'title_en' => $data['title_en'] ?? '',
            'stamps_required' => $data['stamps_required'], 'min_order' => $data['min_order'] ?? 0,
            'points_per_egp' => $data['points_per_egp'] ?? 1, 'reward_type' => 'free_item', 'reward_label' => $data['reward_label'],
            'stamps_expire_days' => $data['stamps_expire_days'] ?? null,
            'qualifying_items' => ['categories' => array_map('intval', $data['categories'] ?? []), 'items' => array_map('intval', $data['items'] ?? [])],
            'milestones' => $milestones, 'card_design' => $data['design'], 'is_active' => $request->boolean('is_active'),
        ])->save();

        return back()->with('ok', __('Loyalty program saved.'));
    }

    /** Ends the current program (cards stay readable) so a new one with other rules can start. */
    public function restart(): RedirectResponse
    {
        LoyaltyProgram::query()->update(['is_active' => false]);
        $old = LoyaltyProgram::query()->latest('id')->first();
        if ($old) {
            $copy = $old->replicate(['tenant_id']);
            $copy->is_active = true;
            $copy->save();
        }

        return back()->with('ok', __('A new program started. Old cards keep their rewards.'));
    }

    // ---- guest desk ----

    /**
     * The guest desk: finds the guest once (mobile or scanned card) and shows everything the counter can do for them —
     * stamps and rewards, packages, tier, the wallet (balance, top-up, gift card sale) and their company vouchers. The
     * actions post to their usual endpoints (stamps here, packages CardsController, wallet WalletController, vouchers
     * the redeem screen), so their rules, shift checks and audit stay the same.
     */
    public function desk(Request $request, LoyaltyService $loyalty, Membership $membership, Packages $packages, ShiftService $shifts, CorporateVouchers $corporate): View
    {
        $q = trim((string) $request->query('q', ''));
        $program = $loyalty->program();
        $customer = $card = null;
        if ($q !== '') {
            // a scanned card (its code, or an older card's link) or a mobile number
            $customer = GuestAccount::fromScan($q) ?? Customer::query()->where('phone', normalize_phone($q) ?? $q)->first();
            $card = $customer && $program ? $loyalty->card($program, $customer)->load(['customer', 'availableRewards', 'transactions' => fn ($t) => $t->limit(10)]) : null;
        }
        $rights = Entitlements::current();
        $user = $request->user();
        // top-up and gift card sale: the wallet's own permission, through the cashier's shift like at the POS
        $walletDesk = WalletService::enabled() && $user->can('wallet.manage');
        $corporateDesk = CorporateVouchers::enabled() && $user->can('vouchers.redeem');

        return view('dashboard.loyalty.desk', [
            'program' => $program, 'q' => $q, 'card' => $card, 'customer' => $customer,
            'milestones' => $program ? $loyalty->milestones($program) : [],
            'tier' => $customer && $rights->allows('membership') ? $membership->tierFor($customer) : null,
            'wallet' => $customer && WalletService::enabled() ? round((float) $customer->credit_balance, 2) : null,
            'packages' => $customer && $rights->allows('prepaid_packages') ? $packages->activeFor($customer) : collect(),
            'onSale' => $rights->allows('prepaid_packages') ? PrepaidPackage::query()->where('is_active', true)->orderBy('sort_order')->get() : collect(),
            'methods' => WalletService::TOPUP_METHODS,
            'walletDesk' => $walletDesk,
            'needsShift' => $walletDesk && $rights->allows('shifts') && ! $shifts->current($user),
            // discount vouchers held by this guest (loyalty card feature); the counter can give one more
            'vouchersOn' => $rights->allows('loyalty_stamps'),
            'vouchers' => $customer && $rights->allows('loyalty_stamps') ? Coupon::heldBy($customer) : collect(),
            // company vouchers linked to the guest's mobile; they are taken on the redeem screen
            'corporateDesk' => $corporateDesk,
            'corporate' => $customer && $corporateDesk ? $corporate->heldBy($customer) : collect(),
        ]);
    }

    public function enroll(Request $request, LoyaltyService $loyalty): RedirectResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:20', 'name' => 'nullable|string|max:100']);
        $phone = normalize_phone($data['phone']);
        if (! $phone) {
            return back()->withErrors(['phone' => __('Enter a valid Egyptian mobile number.')]);
        }
        if ($loyalty->program()) {
            $loyalty->enroll($phone, $data['name'] ?? '', 'pos');
        } else {
            // no stamp program (advanced cards only): the guest still gets a customer record and a card link
            Customer::query()->firstOrCreate(['phone' => $phone], ['name' => mb_substr($data['name'] ?? '', 0, 100), 'source' => 'pos']);
        }

        return redirect()->route('dash.loyalty.desk', ['q' => $phone])->with('ok', __('Card created.'));
    }

    public function stamp(Request $request, LoyaltyCard $card, LoyaltyService $loyalty): RedirectResponse
    {
        $data = $request->validate(['count' => 'required|integer|min:1|max:20', 'note' => 'nullable|string|max:100']);
        abort_unless($card->program?->is_active, 404);
        $loyalty->stamp($card, (int) $data['count'], $request->user(), $request->user()->branch_id, $data['note'] ?? '');

        return back()->with('ok', __('Stamps added.'));
    }

    public function redeem(Request $request, LoyaltyReward $reward, LoyaltyService $loyalty): RedirectResponse
    {
        try {
            $loyalty->redeem($reward, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['reward' => $e->getMessage()]);
        }

        return back()->with('ok', __('Reward redeemed: :label', ['label' => $reward->label]));
    }

    public function claim(Request $request, LoyaltyCard $card, LoyaltyService $loyalty): RedirectResponse
    {
        $data = $request->validate(['milestone' => 'required|integer|min:0']);
        try {
            $reward = $loyalty->claimPoints($card, (int) $data['milestone'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['reward' => $e->getMessage()]);
        }

        return back()->with('ok', __('Reward redeemed: :label', ['label' => $reward->label]));
    }
}
