{{-- The guest's own discount vouchers on their card page (loyalty card feature); nothing when they hold none. --}}
@if ($vouchers->isNotEmpty())
    <div class="mt-5 space-y-2" data-member-vouchers>
        @foreach ($vouchers as $v)
            <div class="rounded-2xl m-surface p-4 shadow-sm ring-1 ring-brand-200" data-member-voucher="{{ $v->code }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm m-muted">🏷 {{ __('Your voucher') }}</div>
                        <div class="text-2xl font-black text-brand-700">{{ $v->headline() }}</div>
                    </div>
                    <code class="rounded-lg border-2 border-dashed border-brand-300 px-2 py-1 font-mono text-base font-extrabold tracking-wider m-text" dir="ltr">{{ $v->code }}</code>
                </div>
                @if ($v->conditions() !== '')<div class="mt-2 text-sm m-muted">{{ $v->conditions() }}</div>@endif
                <div class="mt-1 text-xs m-muted">
                    @if ($v->starts_at && $v->starts_at->isFuture()){{ __('from :date', ['date' => local_time($v->starts_at, 'Y/m/d')]) }} · @endif
                    {{ $v->expires_at ? __('Valid until :date', ['date' => local_time($v->expires_at, 'Y/m/d')]) : __('No end date') }}
                    · {{ __('Order with your mobile number, or show the code at the counter.') }}
                </div>
                <a href="{{ route('menu.show') }}?coupon={{ rawurlencode($v->code) }}" class="btn-primary mt-3 w-full">{{ __('Use it in an order') }}</a>
            </div>
        @endforeach
    </div>
@endif
