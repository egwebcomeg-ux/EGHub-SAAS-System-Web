@extends('layouts.base', ['viteEntries' => ['resources/js/pos.js']])
@php
    $cc = ($mode ?? 'pos') === 'callcenter';
    $kb = \App\Services\KnowledgeBase::canOpen(auth()->user());
@endphp
@section('title', ($cc ? __('Call center') : __('POS')) . ' · ' . tenant()->name)
@section('body_class', 'bg-navy-50 text-navy-900 overflow-hidden')

@section('body')
<div class="dash-app flex h-dvh flex-col">
    {{-- top bar --}}
    <header class="flex flex-wrap items-center gap-2 border-b border-navy-100 bg-white px-3 py-2">
        <a href="{{ route('dash.home') }}" class="btn-ghost min-h-9 px-3">← {{ __('Dashboard') }}</a>
        <div class="font-bold">{{ tenant()->name }} @if ($cc)<span class="badge bg-brand-100 text-brand-700">{{ __('Call center') }}</span>@endif</div>
        @if ($branches->count() > 1)
            <form class="ms-2">
                <select name="branch" class="field min-h-9 py-0" onchange="this.form.submit()">
                    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ tr($b->name, $b->name_en) }}</option>@endforeach
                </select>
            </form>
        @endif
        <div class="ms-auto flex flex-wrap items-center gap-2 whitespace-nowrap max-md:-mx-3 max-md:w-full max-md:flex-nowrap max-md:overflow-x-auto max-md:px-3 max-md:pb-1 max-md:[scrollbar-width:none] [&>*]:shrink-0">
            @unless ($cc)
                {{-- offline mode: connection, sales waiting to sync (opens the sync log), this device's terminal --}}
                <span class="badge min-h-9 bg-emerald-100 px-3 text-emerald-800" data-net data-state="online" role="status" aria-live="polite">{{ __('Connected') }}</span>
                <button type="button" class="btn-ghost hidden min-h-9 px-3" data-sync-open>⟳ <span data-sync-count></span></button>
                <button type="button" class="btn-ghost min-h-9 px-3 ring-brand-500" data-terminal-open title="{{ __('The terminal of this device') }}">🖥 <span data-terminal-label>{{ __('Choose the terminal') }}</span></button>
            @endunless
            @if ($data['shifts'] && !$cc)
                <a href="{{ route('dash.shifts') }}" @class(['badge min-h-9 px-3', 'bg-emerald-100 text-emerald-800' => $shift, 'bg-amber-100 text-amber-800' => !$shift])>
                    {{ $shift ? __('Shift open since :time', ['time' => local_time($shift->opened_at, 'h:i A')]) : __('No open shift') }}
                </a>
            @endif
            @if (!$cc && !empty($data['walletManage']))
                <button type="button" class="btn-ghost min-h-9 px-3" data-topup-open>💳 {{ __('Top up balance') }}</button>
                <button type="button" class="btn-ghost min-h-9 px-3" data-gift-open>🎁 {{ __('Gift card') }}</button>
            @endif
            @if ($cc && auth()->user()->canAny(['delivery.assign', 'delivery.drivers']) && \App\Services\Entitlements::current()->allows('delivery_zones|online_ordering'))
                {{-- delivery orders taken here get their driver on the drivers page (or the orders board) --}}
                <a href="{{ route('dash.drivers') }}" class="btn-ghost min-h-9 px-3" target="_blank" rel="noopener" data-drivers-link>🛵 {{ __('Drivers') }}</a>
            @endif
            @if ($kb)
                {{-- the knowledge base: the answers the team gives on the phone and at the counter (F1) --}}
                @if ($cc)
                    <button type="button" class="btn-ghost min-h-9 px-3" data-kb-open title="F1">📖 {{ __('Knowledge base') }}</button>
                @else
                    <button type="button" class="btn-ghost min-h-9 min-w-9 px-3" data-kb-open aria-label="{{ __('Knowledge base') }}" title="{{ __('Knowledge base') }} (F1)">📖</button>
                @endif
            @endif
            <button type="button" class="btn-ghost min-h-9 px-3" data-open-orders>{{ __('Open orders') }} <span class="badge bg-brand-600 text-white" data-open-count>0</span></button>
            @unless ($cc)
                <button type="button" class="btn-ghost min-h-9 px-3" data-holds-open>⏸ {{ __('Held') }} <span class="badge bg-navy-700 text-white" data-holds-count>0</span></button>
                <button type="button" class="btn-ghost min-h-9 px-3" data-orders-open title="F9">🧾 {{ __('Orders & refunds') }}</button>
                @if (!empty($data['canCash']))
                    <button type="button" class="btn-ghost min-h-9 px-3" data-cash-open>💵 {{ __('Cash in / out') }}</button>
                @endif
            @endunless
            <button type="button" @class(['btn-ghost min-h-9 px-3', 'hidden' => $cc]) data-customer-display title="{{ __('Opens a window to drag onto the screen facing the guest') }}">🖥 {{ __('Customer display') }}</button>
            @unless ($cc)
                {{-- shown only inside the EgHub cashier app (resources/js/native-print.js) --}}
                <button type="button" class="btn-ghost hidden min-h-9 px-3" data-printers-open>🖨 {{ __('Printers') }}</button>
                <button type="button" class="btn-ghost min-h-9 min-w-9 px-3 font-extrabold" data-help-open aria-label="{{ __('Keyboard shortcuts') }}" title="{{ __('Keyboard shortcuts') }}">?</button>
            @endunless
        </div>
    </header>

    @unless ($cc)
        <div class="hidden bg-amber-50 px-4 py-2 text-sm font-bold text-amber-900" data-offline-banner role="alert"></div>
    @endunless
    @if ($needsShift)
        <div class="bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800">
            {{ __('Open a shift to take payments. You can still send orders to the kitchen.') }}
            <a href="{{ route('dash.shifts') }}" class="underline">{{ __('Open shift') }}</a>
        </div>
    @endif

    <div class="flex min-h-0 flex-1">
        {{-- menu --}}
        <section class="flex min-w-0 flex-1 flex-col">
            <div class="flex gap-2 overflow-x-auto border-b border-navy-100 bg-white px-3 py-2" data-cats></div>
            <div class="p-3"><input type="search" class="field" placeholder="{{ __('Search items') }}" data-search></div>
            <div class="grid flex-1 auto-rows-min grid-cols-2 gap-2 overflow-y-auto p-3 pt-0 sm:grid-cols-3 {{ $cc ? 'xl:grid-cols-3 min-[87.5rem]:grid-cols-4' : 'xl:grid-cols-5' }}" data-items></div>
            {{-- phones: the order opens as a sheet from this bar --}}
            <div class="border-t border-navy-100 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:hidden" data-ticket-bar>
                <button type="button" class="btn-primary w-full justify-between" data-ticket-open aria-controls="pos-ticket" aria-expanded="false">
                    <span class="flex items-center gap-2">🧾 {{ __('The order') }} <span class="badge bg-white/20 text-white" data-ticket-count>0</span></span>
                    <span class="font-extrabold" data-ticket-total></span>
                </button>
            </div>
        </section>
        <div class="pos-ticket-backdrop fixed inset-0 z-30 bg-navy-900/50 md:hidden" data-ticket-backdrop aria-hidden="true"></div>

        {{-- ticket --}}
        @if ($cc)
            @include('dashboard.pos.callcenter-ticket')
        @else
        <aside id="pos-ticket" class="pos-ticket flex w-[22rem] shrink-0 flex-col overflow-y-auto border-s border-navy-100 bg-white max-md:fixed max-md:inset-x-0 max-md:bottom-0 max-md:z-40 max-md:max-h-[88dvh] max-md:w-full max-md:rounded-t-3xl max-md:border-s-0 max-md:shadow-2xl lg:w-[24rem]" data-ticket>
            <div class="flex items-center justify-between border-b border-navy-100 px-4 py-2 md:hidden">
                <span class="font-extrabold">🧾 {{ __('The order') }}</span>
                <button type="button" class="grid size-11 place-items-center rounded-xl text-navy-600 hover:bg-navy-50" data-ticket-close aria-label="{{ __('Close') }}">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
                <div class="grid grid-cols-2 gap-1 p-3">
                    <button type="button" class="btn min-h-10 rounded-lg" data-type="takeaway">{{ __('Takeaway') }}</button>
                    <button type="button" class="btn min-h-10 rounded-lg" data-type="dine_in">{{ __('Dine-in') }}</button>
                </div>
            <div class="hidden px-3 pb-2" data-table-box>
                <select class="field" data-table><option value="">{{ __('Choose a table') }}</option></select>
            </div>
            <ul class="min-h-24 flex-1 divide-y divide-navy-50 overflow-y-auto px-3" data-lines></ul>
            <div class="space-y-2 border-t border-navy-100 p-3 text-sm">
                <details>
                    <summary class="cursor-pointer text-navy-500">{{ __('Customer & notes') }}</summary>
                    <div class="mt-2 space-y-2">
                        <input class="field min-h-9" data-customer-name placeholder="{{ __('Customer name') }}" maxlength="100">
                        <input class="field min-h-9" data-customer-phone placeholder="{{ __('Mobile') }}" maxlength="20" dir="ltr" inputmode="tel">
                        <input class="field min-h-9" data-notes placeholder="{{ __('Notes for the order') }}" maxlength="500">
                    </div>
                </details>
                @if ($data['canDiscount'])
                    <div class="flex items-center gap-2">
                        <span class="text-navy-500">{{ __('Discount') }}</span>
                        <input class="field min-h-9 w-24" type="number" min="0" step="0.5" data-discount value="0">
                        <select class="field min-h-9 w-20" data-discount-kind><option value="amount">{{ __('EGP') }}</option><option value="percent">%</option></select>
                    </div>
                @endif
                @if (! empty($data['coupons']))
                    <div class="space-y-1" data-coupon-box>
                        <div class="flex items-center gap-2">
                            <span class="text-navy-500">{{ __('Voucher') }}</span>
                            <input class="field min-h-9 flex-1 uppercase" data-coupon maxlength="30" dir="ltr" autocomplete="off" placeholder="{{ __('Voucher code') }}">
                            <button type="button" class="btn-ghost min-h-9 px-3" data-coupon-apply>{{ __('Apply') }}</button>
                        </div>
                        <p class="hidden text-xs" data-coupon-info></p>
                    </div>
                @endif
                <div class="space-y-0.5" data-totals></div>
                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button type="button" class="btn-ghost" data-send>{{ __('Send to kitchen') }}</button>
                    <button type="button" class="btn-primary" data-pay>{{ __('Pay') }}</button>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="btn-ghost min-h-11" data-hold title="F4">⏸ {{ __('Hold') }}</button>
                    <button type="button" class="min-h-11 w-full rounded-xl text-center text-sm font-semibold text-red-600 hover:bg-red-50" data-clear>{{ __('Clear') }}</button>
                </div>
            </div>
        </aside>
        @endif
    </div>
</div>

{{-- options --}}
<dialog class="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-black/50" data-options-dialog>
    <form method="dialog" class="p-5">
        <h3 class="mb-3 text-lg font-extrabold" data-options-title></h3>
        <div class="space-y-4" data-options-body></div>
        <input class="field mt-4" data-line-notes placeholder="{{ __('Notes for the kitchen') }}" maxlength="200">
        <div class="mt-4 flex gap-2">
            <button value="cancel" class="btn-ghost flex-1">{{ __('Cancel') }}</button>
            <button type="button" class="btn-primary flex-1" data-options-add>{{ __('Add') }}</button>
        </div>
    </form>
</dialog>

{{-- payment --}}
<dialog class="m-auto max-h-[95dvh] w-full max-w-md overflow-y-auto rounded-2xl p-0 backdrop:bg-black/50" data-pay-dialog>
    <form method="dialog" class="p-5">
        <h3 class="text-lg font-extrabold">{{ __('Payment') }}</h3>
        <div class="my-3 text-center text-4xl font-extrabold" data-pay-total></div>
        <div class="-mt-2 mb-3 hidden text-center text-lg font-bold text-brand-700" data-pay-due></div>
        @if (!empty($data['corporate']))
            {{-- corporate vouchers pay first (earliest expiry first); the method below pays the rest --}}
            <div class="mb-3 rounded-xl border border-navy-100 p-2" data-corp-box>
                <button type="button" class="btn-ghost w-full justify-between" data-corp-toggle>
                    <span>🏢 {{ __('Corporate voucher') }}</span><span class="badge bg-brand-600 text-white empty:hidden" data-corp-count></span>
                </button>
                <div class="mt-2 hidden space-y-2" data-corp-panel>
                    <div class="flex gap-2">
                        <input class="field min-w-0 flex-1 uppercase" data-corp-query maxlength="300" dir="ltr" autocomplete="off"
                               placeholder="{{ __('Scan / type the code, or the guest\'s mobile') }}" aria-label="{{ __('Voucher code or guest mobile') }}">
                        <button type="button" class="btn-ghost hidden px-3" data-corp-scan aria-label="{{ __('Scan the QR with the camera') }}">📷</button>
                        <button type="button" class="btn-ghost px-4" data-corp-check>{{ __('Check') }}</button>
                    </div>
                    <video class="hidden w-full rounded-xl bg-black" data-corp-video playsinline muted></video>
                    <p class="hidden rounded-xl bg-red-50 p-2 text-sm font-semibold text-red-700" data-corp-error role="alert"></p>
                    <ul class="max-h-[40dvh] space-y-2 overflow-y-auto" data-corp-results></ul>
                    <div class="hidden divide-y divide-emerald-100 rounded-xl bg-emerald-50 px-3 py-1 text-sm" data-corp-applied></div>
                </div>
            </div>
        @endif
        <div class="grid grid-cols-2 gap-2" data-methods>
            @foreach (['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet')] as $key => $label)
                <button type="button" class="btn-ghost" data-method="{{ $key }}">{{ $label }}</button>
            @endforeach
            @if (!empty($data['wallet']))
                <button type="button" class="btn-ghost col-span-2" data-method="credit">💳 {{ __('From the guest\'s balance') }}</button>
            @endif
        </div>
        @unless ($cc)
            {{-- split payment: part by one method, part by another; what is left goes on the method chosen --}}
            <div class="mt-3">
                <button type="button" class="btn-ghost w-full" data-split-toggle aria-pressed="false">➗ {{ __('Split the payment') }}</button>
                <div class="mt-2 hidden space-y-2 rounded-xl border border-navy-100 p-3" data-split-box>
                    <ul class="divide-y divide-navy-50 text-sm" data-split-list></ul>
                    <div class="flex gap-2">
                        <input class="field min-w-0 flex-1 text-lg" type="number" min="0" step="0.5" inputmode="decimal" data-split-amount aria-label="{{ __('Amount of this part') }}">
                        <button type="button" class="btn-primary px-4" data-split-add>{{ __('Add part') }}</button>
                    </div>
                    <p class="text-sm font-bold text-brand-700" data-split-rest role="status"></p>
                    <p class="text-xs text-navy-500">{{ __('Pick the method above, type the amount, then add the part.') }}</p>
                </div>
            </div>
        @endunless
        @if (!empty($data['wallet']))
            <div class="mt-4 hidden space-y-2" data-credit-box>
                <label class="field-label" for="credit-phone">{{ __('Guest mobile') }}</label>
                <div class="flex gap-2">
                    <input class="field flex-1 text-lg" id="credit-phone" maxlength="20" dir="ltr" inputmode="tel" data-credit-phone>
                    <button type="button" class="btn-ghost px-4" data-credit-lookup>{{ __('Search') }}</button>
                </div>
                <div class="rounded-xl bg-navy-50 p-3 text-sm font-semibold" data-credit-info>{{ __('Enter the guest\'s mobile to see the balance.') }}</div>
            </div>
        @endif
        <div class="mt-4 space-y-2" data-cash-box>
            <label class="field-label">{{ __('Cash received') }}</label>
            <input class="field text-lg" type="number" min="0" step="1" data-tendered>
            <div class="flex flex-wrap gap-2" data-quick></div>
            <div class="text-lg font-bold" data-change></div>
        </div>
        @unless ($cc)
            <p class="mt-3 hidden rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900" data-pay-offline>{{ __('No internet: cash, card, InstaPay or a mobile wallet only. Balance, vouchers, coupons and stamps come back when the internet is back.') }}</p>
        @endunless
        <p class="mt-3 hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-pay-error></p>
        <div class="mt-4 flex gap-2">
            <button value="cancel" class="btn-ghost flex-1">{{ __('Cancel') }}</button>
            <button type="button" class="btn-primary flex-1" data-pay-confirm>{{ __('Confirm payment') }}</button>
        </div>
    </form>
</dialog>

@if (!$cc && !empty($data['corporate']))
    {{-- exception after expiry: approved by a manager signed in here, or with a manager's PIN on this device --}}
    <dialog class="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-black/50" data-corp-exception-dialog>
        <form method="dialog" class="space-y-3 p-5">
            <h3 class="text-lg font-extrabold">{{ __('Exception request') }}</h3>
            <p class="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800" data-exc-voucher></p>
            <label class="block"><span class="field-label">{{ __('Why? (short reason)') }}</span>
                <textarea class="field min-h-16 py-2" maxlength="200" data-exc-reason placeholder="{{ __('e.g. the company asked to extend, staff came on the last day') }}"></textarea></label>
            @if (empty($data['corporateApprove']))
                @if (count($data['approvers']))
                    <label class="block"><span class="field-label">{{ __('Manager') }}</span>
                        <select class="field" data-exc-manager>
                            @foreach ($data['approvers'] as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }}</option>@endforeach
                        </select></label>
                    <label class="block"><span class="field-label">{{ __('Manager PIN') }}</span>
                        <input class="field text-center text-2xl tracking-[.5em]" type="password" inputmode="numeric" maxlength="6" autocomplete="off" dir="ltr" data-exc-pin></label>
                    <p class="text-xs text-navy-500">{{ __('The manager types their PIN here: you stay signed in.') }}</p>
                @else
                    <p class="rounded-xl bg-navy-50 p-3 text-sm" data-exc-no-manager>{{ __('No manager has a PIN yet: a manager can sign in on this device, or set a PIN in Account security.') }}</p>
                @endif
            @else
                <p class="text-sm text-navy-600">{{ __('You approve it as :name. It is recorded in the audit log.', ['name' => auth()->user()->name]) }}</p>
            @endif
            <p class="hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-exc-error role="alert"></p>
            <div class="flex gap-2">
                <button value="cancel" class="btn-ghost flex-1">{{ __('Cancel') }}</button>
                <button type="button" class="btn-primary flex-1" data-exc-confirm>{{ __('Approve') }}</button>
            </div>
        </form>
    </dialog>
@endif

@if (!$cc && !empty($data['walletManage']))
    {{-- wallet top-up --}}
    <dialog class="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-black/50" data-topup-dialog>
        <form method="dialog" class="space-y-3 p-5">
            <h3 class="text-lg font-extrabold">{{ __('Top up balance') }}</h3>
            <div class="flex gap-2">
                <input class="field flex-1 text-lg" maxlength="20" dir="ltr" inputmode="tel" placeholder="{{ __('Guest mobile') }}" data-topup-phone>
                <button type="button" class="btn-ghost px-4" data-topup-lookup>{{ __('Search') }}</button>
            </div>
            <div class="hidden rounded-xl bg-navy-50 p-3 text-sm font-semibold" data-topup-info></div>
            <input class="field" maxlength="100" placeholder="{{ __('Customer name') }}" data-topup-name>
            <input class="field text-lg" type="number" min="1" step="1" placeholder="{{ __('Amount') }}" data-topup-amount>
            <div class="flex flex-wrap gap-2">
                @foreach ([100, 200, 500, 1000] as $v)<button type="button" class="btn-ghost min-h-9 px-3" data-topup-quick="{{ $v }}">{{ $v }}</button>@endforeach
            </div>
            <div class="grid grid-cols-2 gap-2" data-topup-methods>
                @foreach (['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet')] as $key => $label)
                    <button type="button" class="btn-ghost" data-topup-method="{{ $key }}">{{ $label }}</button>
                @endforeach
            </div>
            <p class="hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-topup-error></p>
            <div class="hidden rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-800" data-topup-done></div>
            <div class="flex gap-2">
                <button value="cancel" class="btn-ghost flex-1">{{ __('Close') }}</button>
                <button type="button" class="btn-primary flex-1" data-topup-confirm>{{ __('Add to balance') }}</button>
            </div>
        </form>
    </dialog>

    {{-- gift card sale --}}
    <dialog class="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-black/50" data-gift-dialog>
        <form method="dialog" class="space-y-3 p-5">
            <h3 class="text-lg font-extrabold">{{ __('Sell a gift card') }}</h3>
            <input class="field text-lg" type="number" min="1" step="1" placeholder="{{ __('Amount') }}" data-gift-amount>
            <div class="flex flex-wrap gap-2">
                @foreach ([100, 200, 300, 500] as $v)<button type="button" class="btn-ghost min-h-9 px-3" data-gift-quick="{{ $v }}">{{ $v }}</button>@endforeach
            </div>
            <input class="field" maxlength="20" dir="ltr" inputmode="tel" placeholder="{{ __('Recipient mobile (optional)') }}" data-gift-phone>
            <textarea class="field min-h-16 py-2" maxlength="200" placeholder="{{ __('Message on the card (optional)') }}" data-gift-message></textarea>
            <div class="grid grid-cols-2 gap-2">
                @foreach (['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet')] as $key => $label)
                    <button type="button" class="btn-ghost" data-gift-method="{{ $key }}">{{ $label }}</button>
                @endforeach
            </div>
            <p class="hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-gift-error></p>
            <div class="flex gap-2">
                <button value="cancel" class="btn-ghost flex-1">{{ __('Close') }}</button>
                <button type="button" class="btn-primary flex-1" data-gift-confirm>{{ __('Sell & print') }}</button>
            </div>
        </form>
    </dialog>
@endif

{{-- open orders --}}
<dialog class="m-auto w-full max-w-lg rounded-2xl p-0 backdrop:bg-black/50" data-open-dialog>
    <form method="dialog" class="p-5">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-lg font-extrabold">{{ __('Open orders') }}</h3>
            <button value="cancel" class="grid size-11 place-items-center rounded-xl text-2xl leading-none text-navy-500 hover:bg-navy-50" aria-label="{{ __('Close') }}">×</button>
        </div>
        <ul class="max-h-[60dvh] divide-y divide-navy-50 overflow-y-auto" data-open-list></ul>
    </form>
</dialog>

@unless ($cc)
    {{-- this device's terminal --}}
    <dialog class="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-black/50" data-terminal-dialog>
        <form method="dialog" class="p-5">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-lg font-extrabold">{{ __('The terminal of this device') }}</h3>
                <button value="cancel" class="grid size-11 place-items-center rounded-xl text-2xl leading-none text-navy-500 hover:bg-navy-50" aria-label="{{ __('Close') }}">×</button>
            </div>
            <p class="mb-3 text-sm text-navy-600">{{ __('Pick the till this device stands at. Its shifts and the sales it makes offline carry the terminal code.') }}</p>
            <ul class="max-h-[50dvh] divide-y divide-navy-50 overflow-y-auto" data-terminal-list></ul>
            <p class="mt-3 hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-terminal-error role="alert"></p>
        </form>
    </dialog>

    {{-- sales made offline: waiting, synced (with their real number), refused (retry / mark resolved) --}}
    <dialog class="m-auto w-full max-w-lg rounded-2xl p-0 backdrop:bg-black/50" data-sync-dialog>
        <form method="dialog" class="p-5">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h3 class="text-lg font-extrabold">{{ __('Offline sales') }}</h3>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn-primary min-h-11 px-4" data-sync-now>{{ __('Sync now') }}</button>
                    <button value="cancel" class="grid size-11 place-items-center rounded-xl text-2xl leading-none text-navy-500 hover:bg-navy-50" aria-label="{{ __('Close') }}">×</button>
                </div>
            </div>
            <ul class="max-h-[60dvh] divide-y divide-navy-50 overflow-y-auto" data-sync-list></ul>
        </form>
    </dialog>

    {{-- hold the current ticket under a name / table note --}}
    <dialog class="m-auto w-full max-w-sm rounded-2xl p-0 backdrop:bg-black/50" data-hold-dialog>
        <form method="dialog" class="space-y-3 p-5">
            <h3 class="text-lg font-extrabold">{{ __('Hold the order') }}</h3>
            <label class="block"><span class="field-label">{{ __('Name or table note') }}</span>
                <input class="field text-lg" maxlength="80" data-hold-label placeholder="{{ __('e.g. Ahmed, table 4, the man in blue') }}"></label>
            <p class="hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-hold-error role="alert"></p>
            <div class="flex gap-2">
                <button value="cancel" class="btn-ghost flex-1">{{ __('Cancel') }}</button>
                <button type="button" class="btn-primary flex-1" data-hold-confirm>{{ __('Hold') }}</button>
            </div>
        </form>
    </dialog>

    {{-- held tickets of the branch (and of this device while offline) --}}
    <dialog class="m-auto w-full max-w-lg rounded-2xl p-0 backdrop:bg-black/50" data-holds-dialog>
        <form method="dialog" class="p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-lg font-extrabold">{{ __('Held orders') }}</h3>
                <button value="cancel" class="grid size-11 place-items-center rounded-xl text-2xl leading-none text-navy-500 hover:bg-navy-50" aria-label="{{ __('Close') }}">×</button>
            </div>
            <ul class="max-h-[60dvh] divide-y divide-navy-50 overflow-y-auto" data-holds-list></ul>
        </form>
    </dialog>

    {{-- the branch's recent paid orders: search, reprint, share, refund --}}
    <dialog class="m-auto w-full max-w-xl rounded-2xl p-0 backdrop:bg-black/50" data-orders-dialog>
        <form method="dialog" class="p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-lg font-extrabold">{{ __('Orders & refunds') }}</h3>
                <button value="cancel" class="grid size-11 place-items-center rounded-xl text-2xl leading-none text-navy-500 hover:bg-navy-50" aria-label="{{ __('Close') }}">×</button>
            </div>
            <input type="search" class="field mb-2" data-orders-q maxlength="24" placeholder="{{ __('Search: order number or OFF-K1-3') }}" aria-label="{{ __('Search orders') }}">
            <ul class="max-h-[60dvh] divide-y divide-navy-50 overflow-y-auto" data-orders-list></ul>
        </form>
    </dialog>

    {{-- refund / return: the whole order or some lines, the reason, a manager's OK --}}
    <dialog class="m-auto max-h-[95dvh] w-full max-w-md overflow-y-auto rounded-2xl p-0 backdrop:bg-black/50" data-refund-dialog>
        <form method="dialog" class="space-y-3 p-5">
            <h3 class="text-lg font-extrabold">↩ {{ __('Refund') }} <span class="text-base font-bold text-navy-600" data-refund-title></span></h3>
            <div class="grid grid-cols-2 gap-2">
                <label class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border border-navy-100 px-3 font-bold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"><input type="radio" name="refund_mode" value="full" data-refund-mode class="sr-only" checked> {{ __('The whole order') }}</label>
                <label class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border border-navy-100 px-3 font-bold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"><input type="radio" name="refund_mode" value="lines" data-refund-mode class="sr-only"> {{ __('Some items') }}</label>
            </div>
            <ul class="hidden divide-y divide-navy-50" data-refund-lines></ul>
            <p class="hidden rounded-xl bg-navy-50 p-2 text-xs text-navy-700" data-refund-corporate>{{ __('Corporate vouchers get their value back only with the whole order.') }}</p>
            <label class="block"><span class="field-label">{{ __('Money goes back by') }}</span>
                <select class="field" data-refund-method>
                    <option value="">{{ __('The way it was paid') }}</option>
                    <option value="cash">{{ __('Cash') }}</option><option value="card">{{ __('Card') }}</option>
                    <option value="instapay">InstaPay</option><option value="wallet">{{ __('Mobile wallet') }}</option>
                    @if (!empty($data['wallet']))<option value="credit">{{ __('Wallet balance') }}</option>@endif
                </select></label>
            <label class="block"><span class="field-label">{{ __('Reason') }}</span>
                <textarea class="field min-h-16 py-2" maxlength="200" data-refund-reason placeholder="{{ __('e.g. wrong item, the guest did not like it') }}"></textarea></label>
            @if (empty($data['canRefund']))
                @if (count($data['refundApprovers']))
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block"><span class="field-label">{{ __('Manager') }}</span>
                            <select class="field" data-refund-manager>@foreach ($data['refundApprovers'] as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }}</option>@endforeach</select></label>
                        <label class="block"><span class="field-label">{{ __('Manager PIN') }}</span>
                            <input class="field text-center text-xl tracking-[.4em]" type="password" inputmode="numeric" maxlength="6" autocomplete="off" dir="ltr" data-refund-pin></label>
                    </div>
                @else
                    <p class="rounded-xl bg-navy-50 p-3 text-sm">{{ __('No manager has a PIN yet: a manager can sign in on this device, or set a PIN in Account security.') }}</p>
                @endif
            @else
                <p class="text-sm text-navy-600">{{ __('You approve it as :name. It is recorded in the audit log.', ['name' => auth()->user()->name]) }}</p>
            @endif
            <div class="flex items-center justify-between rounded-xl bg-red-50 p-3 text-red-800"><span class="font-semibold">{{ __('To give back') }}</span><b class="text-2xl" data-refund-amount></b></div>
            <p class="hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-refund-error role="alert"></p>
            <div class="flex gap-2">
                <button value="cancel" class="btn-ghost flex-1">{{ __('Cancel') }}</button>
                <button type="button" class="btn-primary flex-1" data-refund-confirm>{{ __('Refund & print') }}</button>
            </div>
        </form>
    </dialog>

    @if (!empty($data['canCash']))
        {{-- cash put into / taken out of the drawer of the open shift (float, an expense …) --}}
        <dialog class="m-auto w-full max-w-sm rounded-2xl p-0 backdrop:bg-black/50" data-cash-dialog>
            <form method="dialog" class="space-y-3 p-5">
                <h3 class="text-lg font-extrabold">💵 {{ __('Cash in / out') }}</h3>
                <div class="grid grid-cols-2 gap-2">
                    <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-navy-100 font-bold has-[:checked]:border-red-500 has-[:checked]:bg-red-50"><input type="radio" name="cash_type" value="out" class="sr-only" data-cash-type checked> {{ __('Cash out') }}</label>
                    <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-navy-100 font-bold has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50"><input type="radio" name="cash_type" value="in" class="sr-only" data-cash-type> {{ __('Cash in') }}</label>
                </div>
                <input class="field text-2xl" type="number" min="1" step="1" inputmode="decimal" placeholder="{{ __('Amount') }}" data-cash-amount aria-label="{{ __('Amount') }}">
                <select class="field" data-cash-category aria-label="{{ __('Category') }}">
                    <option value="">{{ __('Category (optional)') }}</option>
                    @foreach (\App\Models\CashMovement::CATEGORIES as $key => $label)<option value="{{ $key }}">{{ tr($label[0], $label[1]) }}</option>@endforeach
                </select>
                <input class="field" maxlength="120" data-cash-reason placeholder="{{ __('Reason, e.g. buying ice') }}" aria-label="{{ __('Reason') }}">
                <p class="hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-cash-error role="alert"></p>
                <p class="hidden rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-800" data-cash-done role="status"></p>
                <div class="flex gap-2">
                    <button value="cancel" class="btn-ghost flex-1">{{ __('Close') }}</button>
                    <button type="button" class="btn-primary flex-1" data-cash-confirm>{{ __('Save') }}</button>
                </div>
            </form>
        </dialog>
    @endif

    {{-- keyboard shortcuts --}}
    <dialog class="m-auto w-full max-w-sm rounded-2xl p-0 backdrop:bg-black/50" data-help-dialog>
        <form method="dialog" class="p-5">
            <h3 class="mb-3 text-lg font-extrabold">{{ __('Keyboard shortcuts') }}</h3>
            <dl class="grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-2 text-sm">
                @foreach (['/' => __('Search items'), 'F2' => __('Pay'), 'F4' => __('Hold the order'), 'F8' => __('Open orders'), 'F9' => __('Orders & refunds')] + ($kb ? ['F1' => __('Knowledge base')] : []) + ['Esc' => __('Close the window'), '?' => __('This list')] as $key => $what)
                    <dt><kbd class="inline-grid min-w-10 place-items-center rounded-lg border border-navy-200 bg-navy-50 px-2 py-1 font-mono font-bold" dir="ltr">{{ $key }}</kbd></dt><dd>{{ $what }}</dd>
                @endforeach
            </dl>
            <button value="ok" class="btn-primary mt-4 w-full">{{ __('Got it') }}</button>
        </form>
    </dialog>
@endunless

@if ($kb)
    @include('dashboard.partials.kb-drawer')
@endif

{{-- done --}}
<dialog class="m-auto w-full max-w-sm rounded-2xl p-0 text-center backdrop:bg-black/50" data-done-dialog>
    <form method="dialog" class="p-6">
        <div class="text-sm text-navy-500">{{ __('Order') }}</div>
        <div class="text-5xl font-extrabold" data-done-number></div>
        <div class="mt-2 font-semibold text-emerald-700" data-done-status></div>
        <div class="mt-5 grid grid-cols-2 gap-2">
            <button type="button" class="btn-ghost" data-print="customer">{{ __('Print receipt') }}</button>
            <button type="button" class="btn-ghost" data-print="kitchen">{{ __('Kitchen ticket') }}</button>
        </div>
        <button type="button" class="btn-ghost mt-2 hidden w-full text-emerald-700" data-share-wa>📲 {{ __('Send the receipt on WhatsApp') }}</button>
        <button value="ok" class="btn-primary mt-3 w-full">{{ __('New order') }}</button>
    </form>
</dialog>

@unless ($cc)
    {{-- the EgHub cashier app's printers: one per role (receipt, each kitchen station), chosen and tested on the device --}}
    <dialog class="m-auto max-h-[95dvh] w-full max-w-md overflow-y-auto rounded-2xl p-0 backdrop:bg-black/50" data-printers-dialog>
        <form method="dialog" class="p-5">
            <h2 class="text-lg font-bold">🖨 {{ __('Printers') }}</h2>
            <p class="mt-1 text-sm text-navy-500">{{ __('Choose the printer of each copy on this device. The owner sets automatic printing in POS → Terminals.') }}</p>
            <ul class="mt-2 divide-y divide-navy-100" data-printers-roles></ul>
            <label class="mt-3 flex items-center gap-2 rounded-xl bg-navy-50 p-3 text-sm font-semibold" data-printers-kitchen-row>
                <input type="checkbox" class="size-5" data-printers-kitchen> {{ __('This device prints the kitchen tickets of new orders') }}
            </label>
            <button value="ok" class="btn-primary mt-4 w-full">{{ __('Done') }}</button>
        </form>
    </dialog>
@endunless

@php
    $i18n = [
        // the EgHub cashier app (resources/js/native-print.js)
        'native' => [
            'receipt' => __('Receipt'), 'noPrinter' => __('No printer chosen'), 'choose' => __('Choose a printer'), 'change' => __('Change the printer'),
            'test' => __('Test page'), 'printFailed' => __('Printing failed'),
        ],
        'total' => __('Total'), 'subtotal' => __('Subtotal'), 'service' => __('Service'), 'tax' => __('Tax'), 'discount' => __('Discount'),
        'all' => __('All'), 'empty' => __('Tap items to add them.'), 'required' => __('Required'), 'pickRequired' => __('Please choose: :group', ['group' => '__G__']),
        'chooseTable' => __('Choose a table.'), 'change' => __('Change: :amount', ['amount' => '__A__']), 'short' => __('Short by :amount', ['amount' => '__A__']),
        'sent' => __('Sent to the kitchen'), 'paid' => __('Paid'), 'pay' => __('Pay'), 'noOpen' => __('No open orders.'),
        'table' => __('Table :name', ['name' => '__N__']), 'takeaway' => __('Takeaway'), 'soldOut' => __('Sold out'), 'clearAsk' => __('Clear the order?'),
        'delivery' => __('Delivery'), 'deliveryFee' => __('Delivery fee'), 'phoneFirst' => __('Enter the caller mobile number.'),
        'chooseZone' => __('Choose the delivery zone.'), 'enterAddress' => __('Write the delivery address.'),
        'minOrder' => __('Minimum order for this zone: :amount', ['amount' => '__A__']), 'newCaller' => __('New customer, will be saved with the order.'),
        'outside' => __('This location is outside every delivery zone.'), 'pinned' => __('pinned'),
        'ordersCount' => __(':n previous orders', ['n' => '__N__']), 'minutes' => __(':n min', ['n' => '__N__']), 'placed' => __('Order placed'),
        'balance' => __('Balance: :amount', ['amount' => '__A__']), 'noWallet' => __('No wallet for this mobile number.'),
        'notEnough' => __('Not enough balance: the wallet has :balance.', ['balance' => '__A__']), 'newGuest' => __('New guest, the wallet is created with the top-up.'),
        'topupDone' => __('Added. New balance: :amount', ['amount' => '__A__']), 'printSlip' => __('Print slip'),
        'voucher' => __('Voucher :code', ['code' => '__C__']), 'voucherRemoved' => __('Voucher removed: the cart changed, apply it again.'),
        'chooseMethod' => __('Choose a payment method.'), 'enterAmount' => __('Enter an amount.'), 'enterPhone' => __('Enter the guest\'s mobile to see the balance.'),
        'shareText' => __('Your receipt :ref from :brand: :url', ['ref' => '__R__', 'brand' => tr(\App\Services\Settings::get('brand_name'), \App\Services\Settings::get('brand_name_en')) ?: tenant()->name, 'url' => '__U__']),
    ];
    if (! $cc) {
        // offline mode, the sync log and the receipt printed from the device
        $i18n += [
            'noInternet' => __('No internet connection.'), 'online' => __('Connected'), 'offline' => __('Offline'),
            'pendingCount' => __(':n orders waiting', ['n' => '__N__']), 'refusedCount' => __(':n refused: open the log', ['n' => '__N__']), 'syncing' => __('Syncing…'),
            'offlineBanner' => __('No internet: you can keep selling (cash, card, InstaPay, mobile wallet) for up to :hours hours. The sales sync when it is back.', ['hours' => '__H__']),
            'offBlock_terminal' => __('No internet, and this device has no terminal: offline sales need a terminal.'),
            'offBlock_shift' => __('No internet, and no open shift: open a shift (online) before going offline.'),
            'offBlock_shiftTerminal' => __('No internet: the open shift is not on this device\'s terminal, so offline sales are off.'),
            'offBlock_limit' => __('Offline for too long: new sales are stopped until the device syncs.'),
            'offBlock_nodb' => __('This browser cannot keep sales offline.'),
            'offNoVouchers' => __('Corporate vouchers need the internet.'), 'offNoCredit' => __('The guest\'s balance needs the internet.'),
            'offNoCoupon' => __('Remove the voucher code: codes need the internet.'), 'offMethods' => __('Offline, only cash, card, InstaPay or a mobile wallet can be taken.'),
            'offSend' => __('No internet: take the payment now (the kitchen ticket prints from here), or wait for the internet.'),
            'offSaveFailed' => __('This sale could not be saved on the device.'), 'savedOffline' => __('Saved on this device · syncs when the internet is back'),
            'stPending' => __('Waiting'), 'stSynced' => __('Synced'), 'stRefused' => __('Refused'), 'stResolved' => __('Resolved'),
            'wasRef' => __('#:number (was :ref)', ['number' => '__N__', 'ref' => '__R__']), 'needsReview' => __('Needs review'),
            'retry' => __('Try again'), 'markResolved' => __('Mark resolved'), 'noQueued' => __('No offline sales on this device.'),
            'resolveAsk' => __('Mark this sale resolved? It will not be sent again.'), 'resolveNote' => __('How was it settled? (optional)'),
            'pickTerminal' => __('Choose the terminal'), 'thisDevice' => __('This device'), 'otherDevice' => __('Used by another device'),
            'useTerminal' => __('Use'), 'noTerminals' => __('No terminals yet: the owner or a manager adds them in POS → Terminals.'),
            'takeoverAsk' => __('Another device uses this terminal. Take it over? That device stops selling offline.'),
            'kitchenTicket' => __('Kitchen ticket'), 'dineIn' => __('Dine-in'), 'changeLabel' => __('Change'), 'thanks' => __('Thank you for your visit!'),
            'offlineReceiptNote' => __('Saved offline: the order number follows when the device syncs.'),
            'methods' => ['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet')],
            // held tickets
            'holdName' => __('Write a name or a table note.'), 'onThisDevice' => __('On this device'), 'itemsCount' => __(':n items', ['n' => '__N__']),
            'resume' => __('Continue the order'), 'discard' => __('Discard'), 'noHolds' => __('No held orders.'),
            'replaceAsk' => __('The order on the screen will be replaced. Go on?'), 'discardAsk' => __('Discard this held order?'),
            // split payment
            'splitRest' => __('Left: :amount (goes on :method)', ['amount' => '__A__', 'method' => '__M__']), 'splitDone' => __('The parts cover the order.'),
            'splitMethods' => __('A split payment takes cash, card, InstaPay or a mobile wallet.'), 'remove' => __('Remove'),
            // orders & refunds
            'refund' => __('Refund'), 'refundedFull' => __('Refunded'), 'refundedPart' => __('Refunded :amount', ['amount' => '__A__']),
            'printReceipt' => __('Print receipt'), 'shareWhatsApp' => __('Send the receipt on WhatsApp'), 'noOrdersFound' => __('No paid orders found.'),
            'leftOf' => __(':left of :qty left', ['left' => '__L__', 'qty' => '__Q__']),
            'pager' => \App\Services\Orders\Pager::staffTexts(),
            // cash in / out
            'cashSaved' => __('Saved. Expected cash in the drawer: :amount', ['amount' => '__A__']), 'cashReason' => __('Write the reason.'),
        ];
    }
    if (! empty($data['corporate'])) {
        $i18n += [
            'corpNoCredit' => __('With corporate vouchers, pay the rest in cash, by card, InstaPay or a mobile wallet.'),
            'corpException' => __('Exception'), 'corpNotNeeded' => __('Not needed for this order: stays as it is.'),
            'corpLost' => __('One use: :amount of it is lost.', ['amount' => '__A__']), 'remove' => __('Remove'),
            'corpRest' => __('Left to pay: :amount', ['amount' => '__A__']), 'corpCovered' => __('Fully paid by the vouchers.'),
            'corpBalance' => __('Balance :balance of :value', ['balance' => '__B__', 'value' => '__V__']), 'corpUntil' => __('Valid until :date', ['date' => '__D__']),
            'corpUse' => __('Use'), 'corpAdded' => __('Added'), 'corpAskException' => __('Request an exception'),
            'corpConfirmLink' => __('Confirm the link'), 'corpLink' => __('Link to a mobile'),
            'corpSingleWarn' => __('The order is smaller than the voucher: :amount will be lost.', ['amount' => '__A__']),
            'corpNoneForPhone' => __('No vouchers linked to this mobile.'), 'corpLinkAsk' => __('The guest\'s mobile number'),
            'corpNoCamera' => __('The camera is not available: type the code.'), 'corpApprovedBy' => __('Approved by :name', ['name' => '__N__']),
        ];
    }
    if ($cc) {
        $i18n += [
            'zoneFee' => __('Delivery :fee', ['fee' => '__A__']), 'zoneMin' => __('Minimum :amount', ['amount' => '__A__']), 'zoneTime' => __('About :n min', ['n' => '__N__']),
            'streets' => __(':n streets / areas', ['n' => '__N__']), 'chooseStreet' => __('Street / sub-area'),
            'pickArea' => __('Choose the area to see the fee and the minimum order.'),
            'belowMin' => __('Below this area\'s minimum order (:min): add :short more.', ['min' => '__M__', 'short' => '__S__']),
            'noCustomer' => __('No customer yet'), 'noArea' => __('No area chosen yet'), 'pickup' => __('Pickup'),
            'pinnedOnMap' => __('Location pinned on the map'), 'firstOrder' => __('First order'), 'noName' => __('No name saved'),
            'lastOrder' => __('Last order: :when · :total', ['when' => '__W__', 'total' => '__T__']),
            'stamps' => __(':n of :of stamps', ['n' => '__N__', 'of' => '__O__']), 'rewards' => __(':n rewards ready', ['n' => '__N__']),
            'previousOrders' => __('Previous orders'), 'edit' => __('Edit'),
        ];
        if (($data['pricing']['method'] ?? 'zones') === 'distance') {
            // a branch priced by distance: the customer's place, the suggestions and the rings
            $i18n += [
                'kmAway' => __(':km km from the restaurant', ['km' => '__K__']), 'km' => __(':km km', ['km' => '__K__']),
                'upTo' => __('Up to :km km from the restaurant', ['km' => '__K__']), 'building' => __('Building :n', ['n' => '__N__']),
                'pickPlace' => __('Type the address or move the pin to see the delivery fee.'),
                'distanceNotReady' => __('Delivery by distance is not set up for this branch yet.'),
                'outsideArea' => __('This place is outside the delivery area.'), 'outsideShort' => __('Outside delivery'),
                'choosePlace' => __('Find the customer\'s place on the map, or choose the delivery distance.'),
                'customerPin' => __('The customer'), 'restaurant' => __('The restaurant'), 'noPlace' => __('No place chosen yet'),
                'savedAddress' => __('Saved address'), 'previousAddress' => __('Previous order'),
                'searching' => __('Searching…'), 'searchNone' => __('Nothing found. Pin it on the map or choose the distance.'),
                'searchFailed' => __('The map search is busy, try again in a moment.'), 'searchMap' => __('Search the map too'),
            ];
        }
    }
@endphp
<script type="application/json" id="pos-data">{!! json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
<script type="application/json" id="pos-i18n">{!! json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endsection
