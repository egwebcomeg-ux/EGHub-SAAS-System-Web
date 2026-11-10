{{-- the company's reps: each with a balance of his own, his vouchers out of it, and his orders on the company's account --}}
@php $repAction = 'cursor-pointer list-none rounded-xl border border-navy-200 px-3 py-2 text-sm font-bold text-navy-700 hover:bg-navy-50 [&::-webkit-details-marker]:hidden'; @endphp
<section class="card mb-4 space-y-3" id="reps" data-reps-card>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="section-title">{{ __('Reps') }}</h2>
        <p class="text-sm text-navy-500">{{ __('Each rep has a balance of his own, and his vouchers are issued out of it.') }}</p>
    </div>
    @error('rep')<p class="rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700" data-rep-error>{{ $message }}</p>@enderror

    @forelse ($repRows as $row)
        @php $r = $row['rep']; @endphp
        <article class="rounded-xl border border-navy-100 p-3" data-rep="{{ $r->name }}">
            <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
                <div>
                    <div class="text-lg font-extrabold">{{ $r->name }}@unless ($r->is_active) <span class="badge bg-navy-100 text-navy-500">{{ __('Stopped') }}</span>@endunless</div>
                    @if ($r->phone)<div class="text-sm text-navy-500" dir="ltr">{{ $r->phone }}</div>@endif
                </div>
                <dl class="grid grid-cols-3 gap-x-6 text-sm">
                    <div><dt class="text-navy-500">{{ __('Balance') }}</dt><dd class="text-lg font-extrabold text-brand-700" data-rep-balance>{{ money($r->balance) }}</dd></div>
                    <div><dt class="text-navy-500">{{ __('On his vouchers') }}</dt><dd class="text-lg font-extrabold" data-rep-vouchers>{{ money($row['on_vouchers']) }} <span class="text-xs font-normal text-navy-500">· {{ $row['vouchers'] }}</span></dd></div>
                    <div><dt class="text-navy-500">{{ __('On the company\'s account') }}</dt><dd class="text-lg font-extrabold" data-rep-direct>{{ money($row['direct']) }}@if ($row['direct_left'] !== null) <span class="text-xs font-normal text-navy-500">· {{ __(':left left', ['left' => money($row['direct_left'])]) }}</span>@endif</dd></div>
                </dl>
            </div>

            @if ($live)
                <div class="mt-3 flex flex-wrap items-start gap-2">
                    @if ($r->is_active)
                        <details class="open:basis-full" data-rep-topup>
                            <summary class="{{ $repAction }}">＋ {{ __('Add balance') }}</summary>
                            <form method="post" action="{{ route('dash.corporate.reps.topup', $r) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                @csrf
                                <label class="block"><span class="field-label">{{ __('Amount') }}</span>
                                    <input class="field w-40" name="amount" type="number" min="0.01" step="0.01" required dir="ltr"></label>
                                <button class="btn-primary">{{ __('Put the balance') }}</button>
                            </form>
                        </details>
                        <details class="open:basis-full" data-rep-issue>
                            <summary class="{{ $repAction }}">🎟 {{ __('Issue vouchers') }}</summary>
                            <form method="post" action="{{ route('dash.corporate.reps.issue', $r) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                @csrf
                                <label class="block"><span class="field-label">{{ __('Value of one voucher') }}</span>
                                    <input class="field w-32" name="value" type="number" min="1" step="0.01" required dir="ltr"></label>
                                <label class="block"><span class="field-label">{{ __('How many') }}</span>
                                    <input class="field w-24" name="count" type="number" min="1" max="{{ \App\Services\Corporate\CorporateVouchers::MAX_QUANTITY }}" required dir="ltr"></label>
                                <label class="block"><span class="field-label">{{ __('Valid until') }}</span>
                                    <input class="field" name="expires_on" type="date" min="{{ local_today() }}" value="{{ $contract->expires_on->copy()->max(local_today())->format('Y-m-d') }}" required></label>
                                <label class="block"><span class="field-label">{{ __('How it is used') }}</span>
                                    <select class="field" name="mode">
                                        @foreach (\App\Models\CorporateContract::MODES as $mode => $labels)
                                            <option value="{{ $mode }}" @selected($contract->mode === $mode)>{{ \App\Models\CorporateContract::pick(\App\Models\CorporateContract::MODES, $mode) }}</option>
                                        @endforeach
                                    </select></label>
                                <button class="btn-primary">{{ __('Issue the vouchers') }}</button>
                            </form>
                        </details>
                        <details class="open:basis-full" data-rep-allow>
                            <summary class="{{ $repAction }}">🧾 {{ __('Orders on the company\'s account') }}</summary>
                            <form method="post" action="{{ route('dash.corporate.reps.direct', $r) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                @csrf
                                <label class="block"><span class="field-label">{{ __('He may still order up to') }}</span>
                                    <input class="field w-40" name="left" type="number" min="0" step="0.01" value="{{ $row['direct_left'] }}" required dir="ltr"></label>
                                <button class="btn-primary">{{ __('Save') }}</button>
                                <p class="basis-full text-xs text-navy-500">{{ __('Outside the vouchers: the cashier types the rep\'s mobile, and the order goes on the company\'s account as an amount of its own. 0 = not allowed.') }}</p>
                            </form>
                        </details>
                    @endif
                    @if ($row['unprinted'] > 0)
                        <a href="{{ route('dash.corporate.print', ['contract' => $contract, 'rep_id' => $r->id, 'unprinted' => 1]) }}" target="_blank" class="btn-primary" data-rep-print>🖨 {{ __('Print his new vouchers') }} ({{ $row['unprinted'] }})</a>
                    @endif
                    <details class="open:basis-full" data-rep-edit>
                        <summary class="{{ $repAction }}">{{ __('Edit') }}</summary>
                        <div class="mt-2 flex flex-wrap items-end gap-x-6 gap-y-2">
                            <form method="post" action="{{ route('dash.corporate.reps.update', $r) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <label class="block"><span class="field-label">{{ __('Rep name') }}</span>
                                    <input class="field" name="name" value="{{ $r->name }}" required maxlength="100"></label>
                                <label class="block"><span class="field-label">{{ __('Rep mobile') }}</span>
                                    <input class="field w-44" name="phone" value="{{ $r->phone }}" maxlength="20" dir="ltr" inputmode="tel"></label>
                                <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_active" value="1" class="size-5" @checked($r->is_active)> {{ __('Working') }}</label>
                                <button class="btn-ghost">{{ __('Save') }}</button>
                            </form>
                            @if ($r->balance > 0)
                                <form method="post" action="{{ route('dash.corporate.reps.withdraw', $r) }}" class="flex flex-wrap items-end gap-2" data-rep-withdraw>
                                    @csrf
                                    <label class="block"><span class="field-label">{{ __('Take back to the company') }}</span>
                                        <input class="field w-40" name="amount" type="number" min="0.01" step="0.01" max="{{ $r->balance }}" value="{{ $r->balance }}" required dir="ltr"></label>
                                    <button class="btn-ghost">{{ __('Take back') }}</button>
                                </form>
                            @endif
                        </div>
                    </details>
                </div>
            @endif
        </article>
    @empty
        <p class="rounded-xl bg-navy-50 p-4 text-center text-sm text-navy-500" data-no-reps>{{ __('No reps yet. Add the first one below.') }}</p>
    @endforelse

    @if ($live)
        <form method="post" action="{{ route('dash.corporate.reps.store', $contract) }}" class="flex flex-wrap items-end gap-2 border-t border-navy-100 pt-3" data-rep-new>
            @csrf
            <label class="block min-w-0 flex-1"><span class="field-label">{{ __('Rep name') }}</span>
                <input class="field" name="name" required maxlength="100"></label>
            <label class="block"><span class="field-label">{{ __('Rep mobile') }}</span>
                <input class="field w-44" name="phone" maxlength="20" dir="ltr" inputmode="tel"></label>
            <button class="btn-primary">＋ {{ __('Add the rep') }}</button>
        </form>
    @endif
</section>
