{{-- Cashback (feature cashback): a share of every counted order back to the guest's balance (CardsController::cashback). --}}
<section class="card scroll-mt-4" id="cashback" data-rewards-section="cashback">
    <h2 class="section-title">{{ __('Cashback') }}</h2>
    <p class="muted mb-3">{{ __('A share of every order comes back to the guest’s wallet balance, to spend on the next visit.') }}</p>
    @if (! $wallet)
        <p class="text-sm text-amber-700">{{ __('Cashback needs the wallet, which is part of this pack: contact us if you see this.') }}</p>
    @else
        <form method="post" action="{{ route('dash.cards.cashback') }}" class="grid gap-3 sm:grid-cols-4">
            @csrf
            <label class="block"><span class="field-label">{{ __('Cashback %') }}</span>
                <input class="field" name="percent" type="number" step="0.5" min="0" max="50" value="{{ old('percent', $cashback['percent']) }}" dir="ltr" required></label>
            <label class="block"><span class="field-label">{{ __('From an order of (EGP)') }}</span>
                <input class="field" name="min_order" type="number" step="1" min="0" value="{{ old('min_order', $cashback['min_order']) }}" dir="ltr"></label>
            <label class="block"><span class="field-label">{{ __('Expires after (days)') }}</span>
                <input class="field" name="expire_days" type="number" step="1" min="0" max="730" value="{{ old('expire_days', $cashback['expire_days']) }}" dir="ltr" placeholder="0"></label>
            <div class="flex items-end"><button class="btn-primary w-full">{{ __('Save') }}</button></div>
        </form>
        <div class="mt-3 flex flex-wrap gap-4 text-sm">
            <span class="badge {{ (float) $cashback['percent'] > 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-navy-100 text-navy-600' }}">{{ (float) $cashback['percent'] > 0 ? __('On: :p% back', ['p' => money((float) $cashback['percent'])]) : __('Off') }}</span>
            <span class="text-navy-600">{{ __('Given this month: :amount EGP', ['amount' => money($cashback['month'])]) }}</span>
        </div>
        <p class="mt-3 text-xs text-navy-500">{{ __('Counted once the order is paid or served; a cancelled order takes it back. Shows on the guest’s card as “Cashback”.') }} {{ __('Expiry 0 = it never expires; otherwise unspent cashback is taken back after that many days.') }}</p>
    @endif
</section>
