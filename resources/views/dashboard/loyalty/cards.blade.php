@extends('layouts.dashboard')
@section('title', __('Advanced cards'))

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Advanced cards') }}</h1>
        <p class="muted">{{ __('Cashback, membership tiers and prepaid packages — all on the same card the guest already has.') }}</p>
    </div>
    <div class="flex gap-2">
        @if (\App\Services\Entitlements::current()->allows('loyalty_stamps'))<a href="{{ route('dash.vouchers') }}" class="btn-ghost">{{ __('Vouchers') }}</a>@endif
        <a href="{{ route('dash.loyalty.desk') }}" class="btn-ghost">{{ __('Counter') }}</a>
    </div>
</div>

@if ($errors->any())<div class="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<div class="grid gap-4 xl:grid-cols-2">
    {{-- cashback --}}
    <section class="card" id="cashback">
        <h2 class="section-title">{{ __('Cashback') }}</h2>
        <p class="muted mb-3">{{ __('A share of every order comes back to the guest’s wallet balance, to spend on the next visit.') }}</p>
        @if (! $can['cashback'])
            <p class="text-sm text-navy-500">{{ __('Not in your plan.') }}</p>
        @elseif (! $wallet)
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

    {{-- membership tiers --}}
    <section class="card" id="tiers">
        <h2 class="section-title">{{ __('Membership tiers') }}</h2>
        <p class="muted mb-3">{{ __('Silver, Gold, VIP… reached by visits or spend. The tier’s discount comes off every order placed with the guest’s number.') }}</p>
        @if (! $can['membership'])
            <p class="text-sm text-navy-500">{{ __('Not in your plan.') }}</p>
        @else
            <form method="post" action="{{ route('dash.cards.tiers') }}" class="space-y-2" data-tiers>
                @csrf
                <div class="hidden grid-cols-[1fr_1fr_80px_90px_70px_44px_28px] gap-2 text-xs font-semibold text-navy-500 sm:grid">
                    <span>{{ __('Name') }}</span><span>{{ __('English name') }}</span><span>{{ __('Visits') }}</span><span>{{ __('Spent (EGP)') }}</span><span>{{ __('Discount %') }}</span><span>{{ __('Color') }}</span><span></span>
                </div>
                <div class="space-y-2" data-rows>
                    @foreach ($tiers as $i => $t)
                        <div class="grid grid-cols-2 gap-2 rounded-xl bg-navy-50 p-2 sm:grid-cols-[1.4fr_1fr_72px_88px_64px_44px_28px] sm:bg-transparent sm:p-0" data-row>
                            <input type="hidden" name="tiers[{{ $i }}][id]" value="{{ $t->id }}">
                            <input class="field" name="tiers[{{ $i }}][name]" value="{{ $t->name }}" maxlength="40" required placeholder="{{ __('Gold') }}">
                            <input class="field" name="tiers[{{ $i }}][name_en]" value="{{ $t->name_en }}" maxlength="40" dir="ltr" placeholder="Gold">
                            <input class="field" name="tiers[{{ $i }}][min_visits]" type="number" min="0" value="{{ $t->min_visits }}" dir="ltr">
                            <input class="field" name="tiers[{{ $i }}][min_spent]" type="number" min="0" step="1" value="{{ (int) $t->min_spent }}" dir="ltr">
                            <input class="field" name="tiers[{{ $i }}][discount_percent]" type="number" min="0" max="50" step="0.5" value="{{ $t->discount_percent }}" dir="ltr">
                            <input class="field min-h-11 p-1" name="tiers[{{ $i }}][color]" type="color" value="{{ $t->color }}">
                            <button type="button" class="text-navy-400 hover:text-red-600" data-remove aria-label="{{ __('Remove') }}">✕</button>
                            @if (($members[$t->id] ?? 0) > 0)<span class="col-span-2 text-xs text-navy-500 sm:col-span-7">{{ __(':n guests on this tier today', ['n' => $members[$t->id]]) }}</span>@endif
                        </div>
                    @endforeach
                </div>
                <template data-template>
                    <div class="grid grid-cols-2 gap-2 rounded-xl bg-navy-50 p-2 sm:grid-cols-[1.4fr_1fr_72px_88px_64px_44px_28px] sm:bg-transparent sm:p-0" data-row>
                        <input class="field" name="tiers[__i__][name]" maxlength="40" required placeholder="{{ __('Gold') }}">
                        <input class="field" name="tiers[__i__][name_en]" maxlength="40" dir="ltr" placeholder="Gold">
                        <input class="field" name="tiers[__i__][min_visits]" type="number" min="0" value="0" dir="ltr">
                        <input class="field" name="tiers[__i__][min_spent]" type="number" min="0" step="1" value="0" dir="ltr">
                        <input class="field" name="tiers[__i__][discount_percent]" type="number" min="0" max="50" step="0.5" value="5" dir="ltr">
                        <input class="field min-h-11 p-1" name="tiers[__i__][color]" type="color" value="#C9A227">
                        <button type="button" class="text-navy-400 hover:text-red-600" data-remove aria-label="{{ __('Remove') }}">✕</button>
                    </div>
                </template>
                <div class="flex flex-wrap gap-2 pt-1">
                    <button type="button" class="btn-ghost" data-add>+ {{ __('Add a tier') }}</button>
                    <button class="btn-primary">{{ __('Save tiers') }}</button>
                </div>
            </form>
            <p class="mt-3 text-xs text-navy-500">{{ __('A guest is on the highest tier whose visits and spend they reached. Leave a threshold at 0 to ignore it. Up to 6 tiers.') }}</p>
        @endif
    </section>
</div>

{{-- prepaid packages --}}
<section class="card mt-4" id="packages">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="section-title">{{ __('Prepaid packages') }}</h2>
            <p class="muted">{{ __('“10 coffees for 150 EGP, valid 30 days”: paid once at the counter, the cashier takes one use per visit.') }}</p>
        </div>
    </div>
    @if (! $can['packages'])
        <p class="mt-3 text-sm text-navy-500">{{ __('Not in your plan.') }}</p>
    @else
        <div class="mt-4 grid gap-4 lg:grid-cols-5">
            <form method="post" action="{{ route('dash.cards.packages.store') }}" class="space-y-3 rounded-2xl bg-navy-50 p-4 lg:col-span-2">
                @csrf
                <h3 class="font-bold">{{ __('New package') }}</h3>
                <div class="grid gap-2 sm:grid-cols-2">
                    <input class="field" name="name" value="{{ old('name') }}" maxlength="80" required placeholder="{{ __('10 coffees') }}">
                    <input class="field" name="name_en" value="{{ old('name_en') }}" maxlength="80" dir="ltr" placeholder="10 coffees">
                </div>
                <input class="field" name="description" value="{{ old('description') }}" maxlength="200" placeholder="{{ __('Short description shown to the guest (optional)') }}">
                <div class="grid grid-cols-3 gap-2">
                    <label class="block"><span class="field-label">{{ __('Price') }}</span><input class="field" name="price" type="number" min="1" step="1" value="{{ old('price') }}" dir="ltr" required></label>
                    <label class="block"><span class="field-label">{{ __('Uses') }}</span><input class="field" name="uses" type="number" min="1" max="500" value="{{ old('uses', 10) }}" dir="ltr" required></label>
                    <label class="block"><span class="field-label">{{ __('Valid (days)') }}</span><input class="field" name="valid_days" type="number" min="1" max="730" value="{{ old('valid_days', 30) }}" dir="ltr" required></label>
                </div>
                <details class="text-sm">
                    <summary class="cursor-pointer font-semibold">{{ __('Good for (optional)') }}</summary>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label class="block"><span class="field-label">{{ __('Categories') }}</span>
                            <select class="field" name="categories[]" multiple size="4">@foreach ($categories as $c)<option value="{{ $c->id }}">{{ tr($c->name, $c->name_en) }}</option>@endforeach</select></label>
                        <label class="block"><span class="field-label">{{ __('Items') }}</span>
                            <select class="field" name="items[]" multiple size="4">@foreach ($items as $it)<option value="{{ $it->id }}">{{ tr($it->name, $it->name_en) }}</option>@endforeach</select></label>
                    </div>
                </details>
                <button class="btn-primary w-full">{{ __('Add package') }}</button>
            </form>

            <div class="space-y-3 lg:col-span-3">
                @forelse ($packages as $p)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-navy-100 p-4 {{ $p->is_active ? '' : 'opacity-60' }}">
                        <div>
                            <div class="font-extrabold">{{ $p->displayName() }} <span class="badge {{ $p->is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-navy-100 text-navy-600' }}">{{ $p->is_active ? __('On sale') : __('Paused') }}</span></div>
                            <div class="text-sm text-navy-600">{{ money($p->price) }} {{ __('EGP') }} · {{ __(':n uses', ['n' => $p->uses]) }} · {{ __(':d days', ['d' => $p->valid_days]) }}</div>
                            <div class="text-xs text-navy-500">{{ __('Sold :n, :a still active', ['n' => $p->sold_count, 'a' => $p->active_count]) }}</div>
                        </div>
                        <div class="flex gap-2">
                            <form method="post" action="{{ route('dash.cards.packages.update', $p) }}">@csrf<input type="hidden" name="action" value="toggle"><button class="btn-ghost min-h-9 px-3">{{ $p->is_active ? __('Pause') : __('Resume') }}</button></form>
                            <form method="post" action="{{ route('dash.cards.packages.update', $p) }}" onsubmit="return confirm(@js(__('Remove this package? Guests who bought it keep what is left.')))">@csrf<input type="hidden" name="action" value="delete"><button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Remove') }}</button></form>
                        </div>
                    </div>
                @empty
                    <p class="muted">{{ __('No packages yet. Add the first one on the left — the cashier sells it from the counter page.') }}</p>
                @endforelse

                @if ($recent->isNotEmpty())
                    <h3 class="mt-4 font-bold">{{ __('Recently sold') }}</h3>
                    <div class="overflow-x-auto">
                        <table class="table-base text-sm">
                            <thead><tr><th>{{ __('Guest') }}</th><th>{{ __('Package') }}</th><th>{{ __('Left') }}</th><th>{{ __('Expires') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($recent as $cp)
                                    <tr>
                                        <td><a href="{{ route('dash.loyalty.desk', ['q' => $cp->customer?->phone]) }}" class="text-brand-700 hover:underline" dir="ltr">{{ $cp->customer?->phone }}</a></td>
                                        <td>{{ $cp->name }}</td>
                                        <td dir="ltr">{{ $cp->uses_left }}/{{ $cp->uses_total }}</td>
                                        <td>{{ local_time($cp->expires_at, 'Y/m/d') }}</td>
                                        <td>{{ $cp->statusLabel() }}</td>
                                        <td>@if ($cp->status === 'active' && auth()->user()->can('pos.discount'))
                                            <form method="post" action="{{ route('dash.cards.packages.void', $cp) }}" onsubmit="return confirm(@js(__('Void this package?')))">@csrf<button class="text-xs text-red-600 hover:underline">{{ __('Void') }}</button></form>
                                        @endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</section>

<script>
    // Tier rows: add / remove, renumbering the field names so the server gets a clean list.
    (() => {
        const form = document.querySelector('[data-tiers]');
        if (!form) return;
        const rows = form.querySelector('[data-rows]');
        const tpl = form.querySelector('[data-template]');
        const renumber = () => rows.querySelectorAll('[data-row]').forEach((row, i) => row.querySelectorAll('[name]').forEach((f) => { f.name = f.name.replace(/tiers\[[^\]]*\]/, `tiers[${i}]`); }));
        form.querySelector('[data-add]').addEventListener('click', () => {
            if (rows.querySelectorAll('[data-row]').length >= 6) return;
            rows.insertAdjacentHTML('beforeend', tpl.innerHTML);
            renumber();
        });
        form.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-remove]');
            if (btn) { btn.closest('[data-row]').remove(); renumber(); }
        });
    })();
</script>
@endsection
