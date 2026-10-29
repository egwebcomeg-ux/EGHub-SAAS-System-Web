@extends('layouts.dashboard')
@section('title', __('Guest desk'))

@section('content')
{{-- The guest desk (LoyaltyController::desk): one lookup, then every counter action on that guest, panel by panel. --}}
@php
    $methodLabels = ['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet')];
    $rewardSections = \App\Http\Controllers\Dashboard\RewardsController::sections(auth()->user());
@endphp
<div class="dash-head">
    <div>
        <h1 class="page-title">{{ __('Guest desk') }}</h1>
        <p class="dash-help">{{ __('Find the guest once by mobile or card, then stamp, sell, top up and check their vouchers from here.') }}</p>
    </div>
    <div class="dash-head-actions">
        @if (\App\Http\Controllers\Dashboard\RewardsController::managesRewards(auth()->user()))
            <a href="{{ route('dash.rewards') }}" class="btn-ghost" data-rewards-link>{{ __('Rewards settings') }}</a>
        @endif
    </div>
</div>

@if ($errors->has('package'))<div class="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('package') }}</div>@endif

@if (!$program && $onSale->isEmpty() && ! $vouchersOn && ! \App\Services\Entitlements::current()->allows('membership|cashback|wallet|corporate_vouchers'))
    <div class="card max-w-lg">
        <p class="text-navy-700">{{ __('No active loyalty program yet.') }}</p>
        @if ($rewardSections['program'])<a href="{{ route('dash.rewards') }}#program" class="btn-primary mt-3">{{ __('Set up the loyalty card') }}</a>@endif
    </div>
@else
    <div class="grid gap-4 lg:grid-cols-5">
        <div class="space-y-4 lg:col-span-2">
            <form class="card space-y-3" data-lookup>
                <label class="field-label" for="q">{{ __('Guest mobile or scanned card') }}</label>
                <div class="flex gap-2">
                    <input class="field text-lg" id="q" name="q" value="{{ $q }}" autofocus dir="ltr" placeholder="01xxxxxxxxx" autocomplete="off">
                    <button class="btn-primary">{{ __('Find') }}</button>
                </div>
                <button type="button" class="btn-ghost hidden w-full" data-scan>📷 {{ __('Scan the guest’s QR') }}</button>
                <video class="hidden w-full rounded-xl" data-video playsinline muted></video>
            </form>

            @if ($q !== '' && !$customer)
                <form method="post" action="{{ route('dash.loyalty.enroll') }}" class="card space-y-3">
                    @csrf
                    <p class="font-semibold">{{ __('No card for this number. Create one?') }}</p>
                    <input class="field" name="phone" value="{{ $q }}" required dir="ltr">
                    <input class="field" name="name" placeholder="{{ __('Guest name (optional)') }}" maxlength="100">
                    <button class="btn-primary w-full">{{ __('Create card') }}</button>
                </form>
            @endif

            @if ($customer && $onSale->isNotEmpty())
                <form method="post" action="{{ route('dash.cards.packages.sell', $customer) }}" class="card space-y-3" data-sell>
                    @csrf
                    <h2 class="section-title">{{ __('Sell a package') }}</h2>
                    <select class="field" name="package_id" required>
                        @foreach ($onSale as $p)<option value="{{ $p->id }}">{{ $p->displayName() }} · {{ money($p->price) }} {{ __('EGP') }} · {{ __(':n uses', ['n' => $p->uses]) }} · {{ __(':d days', ['d' => $p->valid_days]) }}</option>@endforeach
                    </select>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach ($methods as $m)
                            <label class="flex min-h-11 cursor-pointer items-center justify-center gap-1 rounded-xl border border-navy-200 text-sm font-semibold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                                <input type="radio" name="method" value="{{ $m }}" class="sr-only" @checked($loop->first)>{{ $methodLabels[$m] }}
                            </label>
                        @endforeach
                    </div>
                    <button class="btn-primary w-full" onclick="return confirm(@js(__('Took the money? Sell the package.')))">{{ __('Sell') }}</button>
                </form>
            @endif

            @if ($customer && $walletDesk)
                {{-- the wallet: the same endpoints and rules as the POS and the wallet page (shift, payment method, audit) --}}
                <section class="card space-y-3" data-wallet-desk>
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="section-title">{{ __('Wallet') }}</h2>
                        <span class="badge bg-brand-50 text-brand-800">{{ __('Balance') }}: <b data-wallet-balance>{{ money($wallet) }}</b></span>
                    </div>
                    @if ($needsShift)
                        <p class="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800" data-needs-shift>
                            {{ __('Open a shift first to take payments.') }} @can('shifts.manage')<a href="{{ route('dash.shifts') }}" class="underline">{{ __('Open shift') }}</a>@endcan
                        </p>
                    @endif
                    <form method="post" action="{{ route('dash.wallet.topup') }}" class="space-y-2" data-wallet-form="topup"
                          data-confirm="{{ __('Took the money? Add it to the guest’s balance.') }}">
                        @csrf
                        <input type="hidden" name="phone" value="{{ $customer->phone }}">
                        <label class="field-label" for="topup-amount">{{ __('Wallet top-up') }}</label>
                        <input class="field" id="topup-amount" name="amount" type="number" min="1" max="{{ \App\Services\Wallet\WalletService::MAX_AMOUNT }}" step="1" required dir="ltr" placeholder="{{ __('Amount') }}">
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            @foreach ($methods as $m)
                                <label class="flex min-h-11 cursor-pointer items-center justify-center gap-1 rounded-xl border border-navy-200 text-sm font-semibold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                                    <input type="radio" name="method" value="{{ $m }}" class="sr-only" @checked($loop->first)>{{ $methodLabels[$m] }}
                                </label>
                            @endforeach
                        </div>
                        <button class="btn-primary w-full">{{ __('Top up') }}</button>
                    </form>
                    <details class="border-t border-navy-100 pt-3" data-gift-sale>
                        <summary class="cursor-pointer font-semibold">🎁 {{ __('Sell a gift card') }}</summary>
                        <form method="post" action="{{ route('dash.wallet.gift.sell') }}" class="mt-3 space-y-2" data-wallet-form="gift"
                              data-confirm="{{ __('Took the money? Sell the gift card.') }}">
                            @csrf
                            <input type="hidden" name="buyer_phone" value="{{ $customer->phone }}">
                            <input class="field" name="amount" type="number" min="1" max="{{ \App\Services\Wallet\WalletService::MAX_AMOUNT }}" step="1" required dir="ltr" placeholder="{{ __('Amount') }}" aria-label="{{ __('Amount') }}">
                            <input class="field" name="recipient_phone" maxlength="20" dir="ltr" placeholder="{{ __('Recipient mobile (optional)') }}" aria-label="{{ __('Recipient mobile (optional)') }}">
                            <input class="field" name="message" maxlength="200" placeholder="{{ __('Message on the card (optional)') }}" aria-label="{{ __('Message on the card (optional)') }}">
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach ($methods as $m)
                                    <label class="flex min-h-11 cursor-pointer items-center justify-center gap-1 rounded-xl border border-navy-200 text-sm font-semibold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                                        <input type="radio" name="method" value="{{ $m }}" class="sr-only" @checked($loop->first)>{{ $methodLabels[$m] }}
                                    </label>
                                @endforeach
                            </div>
                            <button class="btn-primary w-full">{{ __('Sell') }}</button>
                        </form>
                    </details>
                    <div class="hidden rounded-xl p-3 text-sm font-semibold" data-wallet-result role="status"></div>
                </section>
            @endif
        </div>

        @if ($customer)
            <div class="space-y-4 lg:col-span-3">
                <section class="card">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xl font-extrabold">{{ $customer->name ?: __('Guest') }}</span>
                                @if ($tier)<span class="badge text-white" style="background: {{ $tier->color }}">★ {{ $tier->displayName() }}@if ($tier->discount_percent > 0) · {{ money($tier->discount_percent) }}%@endif</span>@endif
                            </div>
                            <div class="muted" dir="ltr">{{ $customer->phone }}</div>
                            <div class="mt-1 text-sm">{{ __(':n visits', ['n' => $customer->visits_count]) }} · {{ money($customer->total_spent) }} {{ __('EGP') }}@if ($wallet !== null) · <b>{{ __('Balance') }}: {{ money($wallet) }}</b>@endif</div>
                        </div>
                        @if ($card)
                            <div class="text-end">
                                <div class="muted">{{ $program->type === 'points' ? __('Points') : __('Stamps') }}</div>
                                <div class="text-4xl font-extrabold text-brand-700">{{ $card->stamps }}@unless ($program->type === 'points')<span class="text-lg text-navy-500">/{{ $program->stamps_required }}</span>@endunless</div>
                            </div>
                        @endif
                    </div>

                    @if ($card)
                        <form method="post" action="{{ route('dash.loyalty.stamp', $card) }}" class="mt-4 flex flex-wrap items-center gap-2">
                            @csrf
                            <input class="field w-20 text-center" name="count" type="number" min="1" max="20" value="1">
                            <input class="field flex-1" name="note" maxlength="100" placeholder="{{ __('Note (optional)') }}">
                            <button class="btn-primary">{{ $program->type === 'points' ? __('Add points') : __('Add stamp') }}</button>
                        </form>
                    @endif

                    @if ($customer->card_token)
                        @php($cardUrl = tenant()->url('card/'.$customer->card_token))
                        @php($cardMessage = __('Your loyalty card at :place: :url', ['place' => tenant()->name, 'url' => $cardUrl]))
                        <div class="mt-4 rounded-xl border border-navy-100 bg-navy-50/60 p-3" data-card-link>
                            <div class="text-sm font-bold text-navy-900">{{ __("The guest's card link") }}</div>
                            <p class="muted mt-0.5 text-xs">{{ __('Send it to the guest: they see their stamps, rewards and balance on their phone, and save it to the home screen.') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <input class="field min-w-0 flex-1 text-sm" value="{{ $cardUrl }}" dir="ltr" readonly onclick="this.select()" aria-label="{{ __("The guest's card link") }}">
                                @if ($customer->phone)
                                    <a href="https://wa.me/{{ whatsapp_number($customer->phone) }}?text={{ rawurlencode($cardMessage) }}" target="_blank" rel="noopener" class="btn-primary min-h-10 px-3" data-card-whatsapp>{{ __('Send on WhatsApp') }}</a>
                                @endif
                                <button type="button" class="btn-ghost min-h-10 px-3" data-copy="{{ $cardUrl }}"
                                        onclick="navigator.clipboard?.writeText(this.dataset.copy).then(() => { this.textContent = @js(__('Copied')) })">{{ __('Copy') }}</button>
                                <a href="{{ $cardUrl }}" target="_blank" rel="noopener" class="btn-ghost min-h-10 px-3">{{ __('Open') }}</a>
                            </div>
                            <p class="muted mt-2 text-xs" data-card-install-hint>{{ __('The guest can add the card to their phone from the link') }}</p>
                        </div>
                    @endif
                </section>

                @if ($packages->isNotEmpty())
                    <section class="card">
                        <h2 class="section-title mb-3">{{ __('Packages') }}</h2>
                        @foreach ($packages as $cp)
                            <form method="post" action="{{ route('dash.cards.packages.use', $cp) }}" class="mb-2 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 p-3" data-package>
                                @csrf
                                <div>
                                    <div class="font-bold text-amber-900">🎟 {{ $cp->name }}</div>
                                    <div class="text-sm text-amber-800" dir="ltr">{{ $cp->uses_left }}/{{ $cp->uses_total }} · {{ __('until :date', ['date' => local_time($cp->expires_at, 'Y/m/d')]) }}</div>
                                </div>
                                <button class="btn-primary min-h-9 px-3" onclick="return confirm(@js(__('Take one use from :name?', ['name' => $cp->name])))">{{ __('Use one') }}</button>
                            </form>
                        @endforeach
                    </section>
                @endif

                @if ($vouchersOn)
                    <section class="card" data-vouchers>
                        <h2 class="section-title mb-3">{{ __('Vouchers') }}</h2>
                        @foreach ($vouchers as $v)
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-brand-50 p-3">
                                <div>
                                    <div class="font-bold text-brand-800">🏷 {{ $v->headline() }}</div>
                                    <div class="text-xs text-navy-600">{{ $v->conditions() }}@if ($v->expires_at) · {{ __('until :date', ['date' => local_time($v->expires_at, 'Y/m/d')]) }}@endif</div>
                                </div>
                                <code class="rounded-lg bg-white px-2 py-1 font-mono text-sm font-bold tracking-wider" dir="ltr">{{ $v->code }}</code>
                            </div>
                        @endforeach
                        @if ($vouchers->isEmpty())<p class="muted mb-3">{{ __('No vouchers for this guest yet.') }}</p>@endif
                        @can('vouchers.give')
                        <form method="post" action="{{ route('dash.vouchers.give', $customer) }}" class="mt-3 grid grid-cols-2 gap-2 border-t border-navy-100 pt-3 sm:grid-cols-4" data-give-voucher>
                            @csrf
                            <p class="col-span-2 font-semibold sm:col-span-4">{{ __('Give a voucher') }}</p>
                            <select class="field" name="type" aria-label="{{ __('Discount type') }}">
                                <option value="percent">{{ __('Percent %') }}</option>
                                <option value="amount">{{ __('Amount (EGP)') }}</option>
                            </select>
                            <input class="field" name="value" type="number" min="1" step="0.5" required placeholder="{{ __('Value') }}" aria-label="{{ __('Value') }}" dir="ltr">
                            <label class="flex items-center gap-1 text-sm"><input class="field" name="days" type="number" min="1" max="365" value="30" required dir="ltr" aria-label="{{ __('Valid for (days)') }}"><span class="shrink-0 text-navy-500">{{ __('days') }}</span></label>
                            <button class="btn-primary" onclick="return confirm(@js(__('Give this guest the voucher?')))">{{ __('Give') }}</button>
                        </form>
                        @endcan
                    </section>
                @endif

                @if ($corporateDesk)
                    <section class="card" data-corporate-desk>
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="section-title">🏢 {{ __('Company vouchers') }}</h2>
                            @if ($customer->phone)<a href="{{ route('dash.redeem', ['phone' => $customer->phone]) }}" class="btn-ghost min-h-9 px-3" data-redeem-link>{{ __('Redeem vouchers') }}</a>@endif
                        </div>
                        @forelse ($corporate as $cv)
                            @php($cvState = $cv->statusKey())
                            <div @class(['mb-2 flex flex-wrap items-center justify-between gap-2 rounded-xl p-3', 'bg-sky-50' => $cvState === 'active', 'bg-navy-50 opacity-70' => $cvState !== 'active']) data-corporate-voucher="{{ $cv->serial }}">
                                <div>
                                    <div class="font-bold">{{ $cv->contract->name }} · <span dir="ltr">{{ $cv->serial }}</span></div>
                                    <div class="text-xs text-navy-600">{{ $cv->statusLabel() }} · {{ __('Valid until :date', ['date' => local_time($cv->expires_on, 'Y/m/d')]) }}</div>
                                </div>
                                <div class="text-end font-extrabold">{{ money($cv->balance) }}@if ($cv->balance != $cv->value)<div class="text-xs font-normal text-navy-500">{{ __('of :value', ['value' => money($cv->value)]) }}</div>@endif</div>
                            </div>
                        @empty
                            <p class="muted">{{ __('No company vouchers linked to this mobile.') }}</p>
                        @endforelse
                    </section>
                @endif

                @if ($card)
                    <section class="card">
                        <h2 class="section-title mb-3">{{ __('Rewards to use') }}</h2>
                        @forelse ($card->availableRewards as $r)
                            <form method="post" action="{{ route('dash.loyalty.redeem', $r) }}" class="mb-2 flex items-center justify-between gap-3 rounded-xl bg-emerald-50 p-3">
                                @csrf
                                <span class="font-bold text-emerald-900">🎁 {{ $r->label }}</span>
                                <button class="btn-primary min-h-9 px-3" onclick="return confirm(@js(__('Give the guest: :label?', ['label' => $r->label])))">{{ __('Redeem') }}</button>
                            </form>
                        @empty
                            <p class="muted">{{ __('No rewards yet.') }}</p>
                        @endforelse
                        @if ($program->type === 'points')
                            @foreach ($milestones as $i => $m)
                                <form method="post" action="{{ route('dash.loyalty.claim', $card) }}" class="mt-2 flex items-center justify-between gap-3 rounded-xl bg-navy-50 p-3">
                                    @csrf<input type="hidden" name="milestone" value="{{ $i }}">
                                    <span>{{ $m['label'] }} · {{ __(':n points', ['n' => $m['at']]) }}</span>
                                    <button class="btn-ghost min-h-9 px-3" @disabled($card->stamps < $m['at'])>{{ __('Exchange') }}</button>
                                </form>
                            @endforeach
                        @endif
                    </section>

                    @if ($card->transactions->isNotEmpty())
                        <section class="card">
                            <h2 class="section-title mb-2">{{ __('History') }}</h2>
                            <ul class="space-y-1 text-sm">
                                @foreach ($card->transactions as $t)
                                    @php($typeLabel = ['stamp' => __('Stamp'), 'points' => __('Points'), 'reward' => __('Reward'), 'redeem' => __('Redeemed'), 'reverse' => __('Reversed'), 'expire' => __('Expired')][$t->type] ?? $t->type)
                                    <li class="flex justify-between gap-3"><span>{{ $typeLabel }} {{ $t->amount ? ($t->amount > 0 ? '+' : '') . $t->amount : '' }} {{ $t->note }}</span><span class="muted">{{ local_time($t->created_at, 'm/d h:i A') }}</span></li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                @endif
            </div>
        @endif
    </div>
@endif

<script>
    // Wallet top-up and gift card sale: the POS's JSON endpoints, answered in place (new balance, slip or card to print).
    (() => {
        const result = document.querySelector('[data-wallet-result]');
        const balance = document.querySelector('[data-wallet-balance]');
        const show = (html, ok) => {
            result.innerHTML = html;
            result.className = `rounded-xl p-3 text-sm font-semibold ${ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'}`;
        };
        const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        const T = @js(['topped' => __('Added. New balance: :balance', ['balance' => '__B__']), 'sold' => __('Gift card sold: :code', ['code' => '__C__']),
            'slip' => __('Print a receipt'), 'print' => __('Print the gift card')]);
        document.querySelectorAll('[data-wallet-form]').forEach((form) => form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!confirm(form.dataset.confirm)) return;
            const button = form.querySelector('button');
            button.disabled = true;
            try {
                const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || (data.errors ? Object.values(data.errors)[0][0] : res.status));
                if (form.dataset.walletForm === 'topup') {
                    const amount = Number(data.balance).toLocaleString('en', { maximumFractionDigits: 2 });
                    balance.textContent = amount;
                    show(`${esc(T.topped.replace('__B__', amount))} · <a href="${esc(data.receipt)}" target="_blank" class="underline">${esc(T.slip)}</a>`, true);
                } else {
                    show(`${esc(T.sold.replace('__C__', data.code))} · <a href="${esc(data.print)}" target="_blank" class="underline">${esc(T.print)}</a>`, true);
                }
                form.reset();
            } catch (err) {
                show(esc(err.message), false);
            } finally {
                button.disabled = false;
            }
        }));
    })();

    // Camera scan where the browser supports it (Chrome on Android, most POS tablets); typing the phone always works.
    (() => {
        if (!('BarcodeDetector' in window)) return;
        const btn = document.querySelector('[data-scan]');
        const video = document.querySelector('[data-video]');
        btn?.classList.remove('hidden');
        btn?.addEventListener('click', async () => {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            video.srcObject = stream;
            video.classList.remove('hidden');
            await video.play();
            const detector = new BarcodeDetector({ formats: ['qr_code'] });
            const tick = async () => {
                const codes = await detector.detect(video).catch(() => []);
                if (codes.length) {
                    stream.getTracks().forEach((t) => t.stop());
                    document.getElementById('q').value = codes[0].rawValue;
                    document.querySelector('[data-lookup]').submit();
                } else requestAnimationFrame(tick);
            };
            tick();
        });
    })();
</script>
@endsection
