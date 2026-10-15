{{-- The guest's prepaid balance (wallet feature) on the card page, with the last movements. --}}
<div class="mt-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100" data-wallet>
    <div class="flex items-center justify-between gap-3">
        <div>
            <div class="text-sm text-navy-500">{{ __('My balance') }}</div>
            <div class="text-3xl font-black" data-wallet-balance>{{ money($wallet['balance']) }} <span class="text-base font-bold">{{ tr($settings['currency'], $settings['currency_en']) }}</span></div>
        </div>
        <span class="grid size-12 place-items-center rounded-2xl bg-emerald-50 text-2xl" aria-hidden="true">💳</span>
    </div>
    <p class="mt-2 text-sm text-navy-600">{{ __('Pay with it at the counter (show this QR) or choose "Pay from my balance" when you order from the menu.') }}</p>
    @if ($wallet['history']->isNotEmpty())
        <ul class="mt-3 space-y-1 border-t border-navy-50 pt-3 text-sm">
            @foreach ($wallet['history'] as $t)
                <li class="flex justify-between gap-3">
                    <span>{{ $t->kindLabel() }}@if ($t->note) <span class="text-navy-500">· {{ $t->note }}</span>@endif</span>
                    <span class="flex gap-3"><b @class(['text-emerald-700' => $t->delta > 0, 'text-red-600' => $t->delta < 0]) dir="ltr">{{ $t->delta > 0 ? '+' : '' }}{{ money($t->delta) }}</b><span class="text-navy-500">{{ local_time($t->created_at, 'Y/m/d') }}</span></span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
