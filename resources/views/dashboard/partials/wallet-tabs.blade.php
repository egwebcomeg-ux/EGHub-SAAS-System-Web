{{-- The wallet area in one place: the balance and gift cards (/wallet), and the packages, cashback and tiers (/loyalty/cards). --}}
@php
    $walletRights = \App\Services\Entitlements::current();
    $walletTabs = array_filter([
        'dash.wallet' => $walletRights->allows('wallet') && auth()->user()->can('wallet.manage') ? __('Balance and gift cards') : null,
        'dash.cards' => $walletRights->allows('cashback|prepaid_packages|membership') && auth()->user()->can('loyalty.manage') ? __('Packages and cashback') : null,
    ]);
@endphp
@if (count($walletTabs) > 1)
    <nav class="mb-4 flex flex-wrap gap-2" aria-label="{{ __('Wallet') }}" data-wallet-tabs>
        @foreach ($walletTabs as $tabRoute => $tabLabel)
            <a href="{{ route($tabRoute) }}" data-wallet-tab="{{ $tabRoute }}" @if (request()->routeIs($tabRoute)) aria-current="page" @endif
               @class(['btn min-h-10 rounded-full px-4', 'bg-navy-800 text-white' => request()->routeIs($tabRoute), 'border border-navy-100 bg-white text-navy-700' => ! request()->routeIs($tabRoute)])>{{ $tabLabel }}</a>
        @endforeach
    </nav>
@endif
