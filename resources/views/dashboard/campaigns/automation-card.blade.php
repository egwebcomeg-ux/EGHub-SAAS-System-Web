{{--
    One automation (welcome, winback or birthday) with its toggle, results and form: a card of the campaigns page's
    "Automations" tab, and the welcome one also on the rewards page ($returnTo = 'rewards' brings the forms back there).
--}}
@php
    $waLocale = \App\Services\WhatsApp\WhatsApp::locale();
    $sample = $waLocale === 'en' ? 'Ahmed' : 'أحمد';
    $hint = match ($kind) {
        'welcome' => __('Sent once, after a guest\'s first counted order (within two days of it).'),
        'winback' => __('Sent when a guest has not visited for the days below; at most once every 90 days.'),
        'birthday' => __('Sent on the guest\'s birthday to guests whose birthday you saved; once a year.'),
    };
    $icon = ['welcome' => '👋', 'winback' => '💛', 'birthday' => '🎂'][$kind];
    // three forms on one page: a failed submit only refills the card it came from
    $old = fn (string $field, $default) => old('kind') === $kind ? old($field, $default) : $default;
@endphp
<section @class(['card flex flex-col gap-3', 'ring-2 ring-emerald-300' => $a->is_enabled]) data-automation="{{ $kind }}">
    <div class="flex items-start justify-between gap-2">
        <div>
            <h2 class="text-lg font-extrabold">{{ $icon }} {{ \App\Models\CampaignAutomation::kindLabel($kind) }}</h2>
            <p class="text-xs text-navy-500">{{ $hint }}</p>
            @if ($kind === 'welcome' && ! ($returnTo ?? null) && \App\Http\Controllers\Dashboard\RewardsController::welcomeParts(auth()->user())['gift'])
                <a href="{{ route('dash.rewards') }}#welcome" class="text-xs text-brand-700 underline" data-welcome-rewards-link>{{ __('The gift on the first online order is next to it on the Rewards page') }}</a>
            @endif
        </div>
        <form method="post" action="{{ route('dash.automations.toggle', $kind) }}">
            @csrf
            @if ($returnTo ?? null)<input type="hidden" name="return" value="{{ $returnTo }}">@endif
            <button @class(['badge min-h-8 px-3', 'bg-emerald-100 text-emerald-800' => $a->is_enabled, 'bg-navy-100 text-navy-600' => ! $a->is_enabled])
                aria-pressed="{{ $a->is_enabled ? 'true' : 'false' }}" data-automation-toggle>{{ $a->is_enabled ? __('On') : __('Off') }}</button>
        </form>
    </div>

    <dl class="grid grid-cols-3 gap-2 rounded-xl bg-navy-50 p-3 text-center text-sm" aria-label="{{ __('Last 30 days') }}">
        <div><dt class="text-xs text-navy-500">{{ __('Sent') }}</dt><dd class="text-lg font-extrabold">{{ number_format($r['sent']) }}</dd></div>
        <div><dt class="text-xs text-navy-500">{{ __('Coupons used') }}</dt><dd class="text-lg font-extrabold">{{ number_format($r['redeemed']) }}</dd></div>
        <div><dt class="text-xs text-navy-500">{{ __('Revenue') }}</dt><dd class="text-lg font-extrabold">{{ money($r['revenue']) }}</dd></div>
        <div class="col-span-3 text-xs text-navy-500">{{ __('Last 30 days') }}</div>
    </dl>

    <form method="post" action="{{ route('dash.automations.update', $kind) }}" class="space-y-3">
        @csrf
        <input type="hidden" name="kind" value="{{ $kind }}">
        @if ($returnTo ?? null)<input type="hidden" name="return" value="{{ $returnTo }}">@endif
        <input type="hidden" name="is_enabled" value="{{ $a->is_enabled ? 1 : 0 }}">
        <div>
            <label class="field-label" for="am-{{ $kind }}">{{ __('Message') }}</label>
            <textarea class="field min-h-28 py-2" id="am-{{ $kind }}" name="message" maxlength="1000" required>{{ $old('message', $a->message) }}</textarea>
            <div class="mt-1 flex flex-wrap gap-1.5 text-xs">
                @foreach (['{name}', '{restaurant}', '{coupon}', '{link}'] as $ph)
                    <span class="rounded-lg bg-navy-100 px-2 py-1 font-mono" dir="ltr">{{ $ph }}</span>
                @endforeach
            </div>
            @if (old('kind') === $kind) @error('message')<div class="field-error">{{ $message }}</div>@enderror @endif
        </div>

        @if ($kind === 'winback')
            <div>
                <label class="field-label" for="ad-{{ $kind }}">{{ __('Days without a visit') }}</label>
                <input class="field" id="ad-{{ $kind }}" name="days" type="number" min="7" max="365" value="{{ $old('days', $a->days) }}" dir="ltr" required>
                @if (old('kind') === $kind) @error('days')<div class="field-error">{{ $message }}</div>@enderror @endif
            </div>
        @endif

        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="field-label" for="af-{{ $kind }}">{{ __('Send from (hour)') }}</label>
                <input class="field" id="af-{{ $kind }}" name="send_from" type="number" min="0" max="23" value="{{ $old('send_from', $a->send_from) }}" dir="ltr" required>
            </div>
            <div>
                <label class="field-label" for="at-{{ $kind }}">{{ __('Until (hour)') }}</label>
                <input class="field" id="at-{{ $kind }}" name="send_to" type="number" min="1" max="24" value="{{ $old('send_to', $a->send_to) }}" dir="ltr" required>
                @if (old('kind') === $kind) @error('send_to')<div class="field-error">{{ $message }}</div>@enderror @endif
            </div>
        </div>

        <div>
            <label class="field-label" for="ac-{{ $kind }}">{{ __('Coupon (optional)') }}</label>
            <select class="field" id="ac-{{ $kind }}" name="coupon_id">
                <option value="">{{ __('No coupon') }}</option>
                @foreach ($coupons as $coupon)
                    <option value="{{ $coupon->id }}" @selected((int) $old('coupon_id', $a->coupon_id) === $coupon->id)>{{ $coupon->code }} · -{{ $coupon->label() }}</option>
                @endforeach
            </select>
            <details class="mt-2 rounded-xl border border-navy-100 p-2 text-sm">
                <summary class="cursor-pointer font-semibold">{{ __('Or make a new coupon') }}</summary>
                <div class="mt-2 grid grid-cols-3 gap-2">
                    <input class="field uppercase col-span-3" name="coupon_code" maxlength="30" dir="ltr" placeholder="WELCOME10" value="{{ $old('coupon_code', '') }}">
                    <input class="field col-span-2" name="coupon_value" type="number" min="1" step="1" value="{{ $old('coupon_value', 10) }}" dir="ltr">
                    <select class="field" name="coupon_type">
                        <option value="percent" @selected($old('coupon_type', 'percent') === 'percent')>%</option>
                        <option value="amount" @selected($old('coupon_type', '') === 'amount')>{{ __('EGP') }}</option>
                    </select>
                </div>
                <p class="mt-1 text-xs text-navy-500">{{ __('Each guest can use it once; it never expires.') }}</p>
                @if (old('kind') === $kind) @error('coupon_code')<div class="field-error">{{ $message }}</div>@enderror @endif
                @if (old('kind') === $kind) @error('coupon_value')<div class="field-error">{{ $message }}</div>@enderror @endif
            </details>
        </div>

        <div class="rounded-2xl bg-[#e5ddd5] p-3" dir="{{ $waLocale === 'en' ? 'ltr' : 'rtl' }}">
            <div class="whitespace-pre-line rounded-xl rounded-ss-none bg-white p-3 text-sm leading-relaxed shadow-sm" data-automation-preview>{{ strtr($a->message, ['{name}' => $sample, '{restaurant}' => \App\Services\Notify\GuestNotifier::senderName(), '{coupon}' => $a->coupon?->code ?? '', '{link}' => $menuUrl]) }}</div>
        </div>

        <button class="btn-primary w-full">{{ __('Save') }}</button>
    </form>
</section>
