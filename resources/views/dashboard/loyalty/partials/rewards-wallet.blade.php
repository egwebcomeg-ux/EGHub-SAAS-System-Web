{{--
    The wallet (feature wallet, permission wallet.manage): what it holds today and where its money comes from. The
    balances, ledger and gift cards stay on the wallet page; top-ups and gift cards are sold at the guest desk or the POS.
--}}
<section class="card scroll-mt-4" id="wallet" data-rewards-section="wallet">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="section-title">{{ __('Wallet') }}</h2>
            <p class="muted">{{ __('The guest’s prepaid balance: top-ups, gift cards, packages and cashback.') }}</p>
        </div>
        <a href="{{ route('dash.wallet') }}" class="btn-ghost" data-wallet-page-link>{{ __('Balances and gift cards') }}</a>
    </div>
    <div class="mt-3 grid grid-cols-2 gap-3 sm:gap-4">
        <div class="stat-card"><div class="stat-label">{{ __('Balances owed to guests') }}</div><div class="stat-value text-brand-700">{{ money($walletTotals['liability']) }}</div></div>
        <div class="stat-card"><div class="stat-label">{{ __('Unused gift cards') }}</div><div class="stat-value">{{ money($walletTotals['gifts']) }}</div></div>
    </div>
    <ul class="mt-3 list-disc space-y-1 ps-5 text-sm text-navy-600">
        <li>{{ __('Top-ups and gift cards are sold at the guest desk or the POS, through the cashier’s shift.') }}</li>
        <li>{{ __('A gift card is valid for :n months from the day it is sold.', ['n' => \App\Models\GiftCard::VALID_MONTHS]) }}</li>
        <li>{{ __('Guests pay from their balance at the counter and online; a cancelled order puts the money back.') }}</li>
    </ul>
</section>
