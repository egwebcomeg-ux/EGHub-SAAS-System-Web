{{-- "My vouchers": the corporate vouchers linked to this guest's mobile (company, balance, expiry); nothing when none. --}}
@if (($corporate ?? collect())->isNotEmpty())
    <div class="mt-5 space-y-2" data-corporate-vouchers>
        <h2 class="section-title">🏢 {{ __('My vouchers') }}</h2>
        @foreach ($corporate as $cv)
            @php($cvState = $cv->statusKey())
            <div @class(['rounded-2xl m-surface p-4 shadow-sm ring-1', 'ring-brand-200' => $cvState === 'active', 'ring-black/10 opacity-70' => $cvState !== 'active'])
                 data-corporate-voucher="{{ $cv->serial }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm m-muted">{{ $cv->contract->name }}</div>
                        <div class="text-2xl font-black text-brand-700">{{ money($cv->balance) }} <span class="text-sm font-bold">{{ tr($settings['currency'], $settings['currency_en']) }}</span></div>
                        @if ($cv->balance != $cv->value)<div class="text-xs m-muted">{{ __('of :value', ['value' => money($cv->value)]) }}</div>@endif
                    </div>
                    <span class="badge m-surface-2 m-muted" dir="ltr">{{ $cv->serial }}</span>
                </div>
                <div class="mt-2 text-xs m-muted">
                    {{ $cv->statusLabel() }} · {{ __('Valid until :date', ['date' => local_time($cv->expires_on, 'Y/m/d')]) }}
                    @if ($cvState === 'active') · {{ __('At the counter, give the cashier your mobile number to pay with it.') }}@endif
                </div>
            </div>
        @endforeach
    </div>
@endif
