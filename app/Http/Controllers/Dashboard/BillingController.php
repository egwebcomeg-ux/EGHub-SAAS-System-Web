<?php

namespace App\Http\Controllers\Dashboard;

use App\Billing\Billing;
use App\Billing\Credits;
use App\Billing\LaunchOffer;
use App\Billing\OnlinePayments;
use App\Billing\PaymentMethods;
use App\Http\Controllers\Controller;
use App\Models\AddonPrice;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The restaurant's own subscription: compare plans, get an invoice, pay it online (OnlinePayments: card, wallets,
 * installments) or by transfer and send the reference.
 * Invoices are platform rows (no tenant scope), so every lookup here filters by the current tenant explicitly.
 */
class BillingController extends Controller
{
    /** The per-screen add-on (EgHub screens): sold by quantity. */
    public const SCREENS_ADDON = 'screens';

    public const MAX_SCREENS_PER_ORDER = 20;

    public function index(Credits $credits, OnlinePayments $online): View
    {
        $subscription = tenant()->subscription()->with('plan')->first();
        // the public line-up, plus an older plan the restaurant is still on so it can renew it (never an archived one)
        $plans = Plan::query()->offered()->where(fn ($q) => $q->where('is_public', true)->orWhere('id', $subscription?->plan_id))
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
            // the extra branch it already has free with the launch offer: until when, then billed at its price
            'branchOfferEnds' => LaunchOffer::coversBranchNow($subscription) && ($myAddons[LaunchOffer::ADDON] ?? 0) > 0
                ? $subscription->branch_offer_until : null,
            'creditPacks' => AddonPrice::query()->where('is_active', true)->where('type', 'credits')->orderBy('sort_order')->get(),
            'creditBalance' => $credits->balance(tenant()),
            'myAddons' => $myAddons,
            'onlineEnabled' => $online->payNowMethods() !== [] || $online->installmentMethods() !== [],
            'installmentsPromise' => $online->installmentsPromise(),
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
        // screens are bought by the number the restaurant needs (one invoice for all of them)
        $quantity = $addon->key === self::SCREENS_ADDON ? max(1, min(self::MAX_SCREENS_PER_ORDER, (int) $request->input('quantity', 1))) : 1;
        $invoice = $billing->invoiceForAddon(tenant(), $addon, $quantity);
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
            // a public plan, or the older plan the restaurant is on (renewal); never a contact-sales or archived plan
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('contact_sales', false)->whereNull('archived_at')
                ->where(fn ($q) => $q->where('is_public', true)->orWhere('id', $current))],
            'months' => ['required', Rule::in(Plan::CYCLES)],
        ]);
        $invoice = $billing->invoiceForPlan(tenant(), Plan::query()->findOrFail($data['plan_id']), (int) $data['months']);

        return redirect()->route('dash.billing.invoice', $invoice->number);
    }

    public function invoice(string $number, OnlinePayments $online): View
    {
        $invoice = $this->find($number)->load('plan', 'payments');
        $open = $invoice->status === 'open' && $invoice->total > 0;

        return view('dashboard.billing.invoice', [
            'invoice' => $invoice,
            'methods' => PaymentMethods::manual(),
            'instructions' => PaymentMethods::instructions(),
            // a transfer the restaurant claimed and EgWeb has not confirmed yet (online attempts are not claims)
            'pending' => $invoice->payments->first(fn ($p) => $p->status === 'pending' && ! $p->isOnline()),
            'online' => $invoice->payments->filter(fn ($p) => $p->isOnline())->sortByDesc('id')->first(),
            'payNow' => $open ? $online->payNowMethods() : [],
            'installments' => $open && $online->installmentsAllowedFor($invoice) ? $online->installmentMethods() : [],
        ]);
    }

    /** "Pay online": opens the gateway's hosted checkout for this invoice (now, or in installments). */
    public function pay(Request $request, string $number, OnlinePayments $online): RedirectResponse
    {
        $invoice = $this->find($number);
        abort_unless($invoice->status === 'open', 404);
        $data = $request->validate(['plan' => ['required', Rule::in(OnlinePayments::PLANS)]]);
        try {
            $url = $online->start($invoice, $data['plan'], route('dash.billing.return', $invoice->number), route('dash.billing.webhook', $online->gateway()->key()));
        } catch (\DomainException) {
            return back()->withErrors(['online' => __('This way of paying is not available for this invoice.')]);
        } catch (\RuntimeException $e) {
            Log::warning('Online checkout failed', ['invoice' => $invoice->number, 'error' => $e->getMessage()]);

            return back()->withErrors(['online' => __('Online payment is unavailable right now. Try again in a few minutes or pay by transfer.')]);
        }

        return redirect()->away($url);
    }

    /**
     * Where the gateway sends the restaurant back. Its query string is never trusted: the invoice and the payment are
     * read from the database (asking the gateway server-to-server while the attempt is still pending).
     */
    public function returned(string $number, OnlinePayments $online): RedirectResponse
    {
        $invoice = $this->find($number);
        $payment = $online->refresh($invoice);
        $to = redirect()->route('dash.billing.invoice', $invoice->number);
        if ($invoice->fresh()->status === 'paid') {
            return $to->with('ok', __('Payment received. Thank you! Your subscription is updated.'));
        }
        if ($payment?->status === 'failed') {
            return $to->withErrors(['online' => __('The payment did not go through. You can try again or choose another way to pay.')]);
        }

        return $to->with('ok', __('We are confirming your payment with the bank. This page updates once it is done.'));
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
