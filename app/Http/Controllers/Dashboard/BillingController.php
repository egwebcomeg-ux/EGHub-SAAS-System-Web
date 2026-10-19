<?php

namespace App\Http\Controllers\Dashboard;

use App\Billing\Billing;
use App\Billing\Credits;
use App\Billing\LaunchOffer;
use App\Billing\PaymentMethods;
use App\Http\Controllers\Controller;
use App\Models\AddonPrice;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The restaurant's own subscription: compare plans, get an invoice, pay by transfer and send the reference.
 * Invoices are platform rows (no tenant scope), so every lookup here filters by the current tenant explicitly.
 */
class BillingController extends Controller
{
    public function index(Credits $credits): View
    {
        $subscription = tenant()->subscription()->with('plan')->first();
        // the public line-up, plus an older plan the restaurant is still on so it can renew it
        $plans = Plan::query()->where(fn ($q) => $q->where('is_public', true)->orWhere('id', $subscription?->plan_id))
            ->with('features')->orderBy('sort_order')->get();
        $myAddons = $subscription?->addons()->pluck('quantity', 'key')->all() ?? [];

        return view('dashboard.billing.index', [
            'subscription' => $subscription,
            'plans' => $plans,
            'matrix' => $plans->mapWithKeys(fn (Plan $p) => [$p->id => $p->entitlements()]),
            'invoices' => Invoice::query()->where('tenant_id', tenant()->getTenantKey())->latest('id')->limit(20)->get(),
            // add-ons on sale, and the ones this restaurant already has even if they are no longer sold
            'addons' => AddonPrice::query()->where('type', '!=', 'credits')
                ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('key', array_keys(array_filter($myAddons))))->orderBy('sort_order')->get(),
            'launchOffer' => app(Billing::class)->launchOfferCoversNextBranch($subscription, new AddonPrice(['key' => LaunchOffer::ADDON]))
                ? $subscription->branch_offer_until : null,
            'creditPacks' => AddonPrice::query()->where('is_active', true)->where('type', 'credits')->orderBy('sort_order')->get(),
            'creditBalance' => $credits->balance(tenant()),
            'myAddons' => $myAddons,
        ]);
    }

    public function addon(Request $request, string $key, Billing $billing): RedirectResponse
    {
        $addon = AddonPrice::query()->where('is_active', true)->where('key', $key)->firstOrFail();
        if ($addon->isCreditPack()) {
            return redirect()->route('dash.billing.invoice', $billing->invoiceForAddon(tenant(), $addon, 1)->number);
        }
        $mine = (int) tenant()->subscription()->first()?->addons()->where('key', $key)->value('quantity');
        if ($addon->isFeaturePack() && $mine > 0) {
            return back()->with('ok', __('This add-on is already active.'));
        }
        $plan = tenant()->subscription()->with('plan')->first()?->plan;
        if ($addon->key === 'branches' && $mine >= AddonPrice::MAX_SELF_SERVE_EXTRA_BRANCHES) {
            return back()->withErrors(['addon' => __('More than :count branches is the Enterprise plan. Message us and we will set it up.', ['count' => AddonPrice::MAX_SELF_SERVE_EXTRA_BRANCHES + 1])]);
        }
        if (! $addon->availableFor($plan)) {
            return back()->withErrors(['addon' => __('This add-on needs at least the :plan plan.', ['plan' => Plan::query()->where('code', $addon->min_plan)->first()?->displayName()])]);
        }
        if ($addon->isFeaturePack() && $addon->features && collect($addon->features)->every(fn ($f) => Entitlements::current()->allows($f))) {
            return back()->with('ok', __('Everything in this add-on is already included in your subscription.'));
        }
        $invoice = $billing->invoiceForAddon(tenant(), $addon, 1);
        if ((float) $invoice->total === 0.0) {
            // nothing to pay (the launch offer's free branch): switched on at once
            $billing->markPaid($invoice, 'offer', null, 0, 'offer-'.$invoice->number);

            return redirect()->route('dash.billing')->with('ok', __('Your extra branch is on, free with the launch offer.'));
        }

        return redirect()->route('dash.billing.invoice', $invoice->number);
    }

    public function checkout(Request $request, Billing $billing): RedirectResponse
    {
        $current = tenant()->subscription()->value('plan_id');
        $data = $request->validate([
            // a public plan, or the older plan the restaurant is on (renewal); never a contact-sales plan
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('contact_sales', false)
                ->where(fn ($q) => $q->where('is_public', true)->orWhere('id', $current))],
            'months' => ['required', Rule::in(Plan::CYCLES)],
        ]);
        $invoice = $billing->invoiceForPlan(tenant(), Plan::query()->findOrFail($data['plan_id']), (int) $data['months']);

        return redirect()->route('dash.billing.invoice', $invoice->number);
    }

    public function invoice(string $number): View
    {
        $invoice = $this->find($number);

        return view('dashboard.billing.invoice', [
            'invoice' => $invoice->load('plan', 'payments'),
            'methods' => PaymentMethods::manual(),
            'instructions' => PaymentMethods::instructions(),
            'pending' => $invoice->payments->firstWhere('status', 'pending'),
        ]);
    }

    /** "I transferred the money": stored as a pending payment; EgWeb confirms it from the super admin. */
    public function claim(Request $request, string $number, Billing $billing): RedirectResponse
    {
        $invoice = $this->find($number);
        abort_unless($invoice->status === 'open', 404);
        $data = $request->validate([
            'method' => ['required', Rule::in(array_column(PaymentMethods::manual(), 'key'))],
            'reference' => 'required|string|max:80',
            'sender' => 'nullable|string|max:40',
        ]);
        $billing->claimManualPayment($invoice, $data['method'], $data['reference'], $data['sender'] ?? null);

        return back()->with('ok', __('Thank you! We will confirm your transfer shortly and activate your plan.'));
    }

    private function find(string $number): Invoice
    {
        return Invoice::query()->where('tenant_id', tenant()->getTenantKey())->where('number', $number)->firstOrFail();
    }
}
