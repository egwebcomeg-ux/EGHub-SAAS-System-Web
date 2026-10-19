@extends('layouts.dashboard')
@section('title', __('Offers & popups'))

@section('content')
@php
    $days = [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')];
    $liveBanners = $banners->filter(fn ($b) => ! $b->is_popup && $b->is_active && (! $b->ends_on || $b->ends_on->gte(today())))->count();
@endphp
<x-dash.page-header :title="__('Offers & popups')" :help="__('Offers lower prices automatically on the menu and at the cashier during their hours.')" />

<div class="grid gap-4 xl:grid-cols-5">
    <section class="space-y-3 xl:col-span-3">
        <h2 class="section-title">{{ __('Timed offers') }}</h2>
        @forelse ($offers as $o)
            @php($live = $o->runsAt(now(), $o->branch_id))
            <div class="card flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="badge bg-emerald-600 text-white" dir="ltr">-{{ $o->discount_percent }}%</span>
                        <b>{{ $o->title }}</b>
                        @if ($live)<span class="badge bg-emerald-100 text-emerald-800">{{ __('Running now') }}</span>@endif
                        @unless ($o->is_active)<span class="badge bg-navy-100 text-navy-500">{{ __('Paused') }}</span>@endunless
                    </div>
                    <div class="mt-1 text-sm text-navy-500">
                        {{ $o->category?->name ?? __('Whole menu') }} ·
                        {{ $o->days ? collect($o->days)->map(fn ($d) => $days[$d])->implode('، ') : __('Every day') }} ·
                        {{ $o->starts_at ? substr($o->starts_at, 0, 5) . ' – ' . substr($o->ends_at, 0, 5) : __('All day') }}
                        @if ($o->branch) · {{ $o->branch->name }}@endif
                        @if ($o->ends_on) · {{ __('until :date', ['date' => $o->ends_on->format('Y/m/d')]) }}@endif
                    </div>
                </div>
                <div class="flex gap-2">
                    <form method="post" action="{{ route('dash.offers.toggle', $o) }}">@csrf<button class="btn-ghost min-h-9 px-3">{{ $o->is_active ? __('Pause') : __('Resume') }}</button></form>
                    <form method="post" action="{{ route('dash.offers.delete', $o) }}" onsubmit="return confirm(@js(__('Delete this offer?')))">@csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Delete') }}</button></form>
                </div>
            </div>
        @empty
            <p class="empty-state">{{ __('No offers yet. Example: 20% off cold drinks every day 3–6 PM.') }}</p>
        @endforelse

        {{--
            Every announcement of the menu in one place: the banners (one of them may open over the menu as soon as it
            opens, the popup) and the ticker line. Only one thing moves at the top of the menu (App\Support\MenuTicker).
        --}}
        <div class="space-y-3 pt-4" id="announcements" data-announcements>
        <h2 class="section-title">{{ __('Banners & announcements') }} <span class="badge bg-navy-100 text-navy-700" title="{{ __('Banners in the strip') }}">{{ $liveBanners }} / {{ $maxBanners }}</span></h2>
        <p class="muted -mt-2">{{ __('A swipeable strip of pictures under the menu header. Up to :n active at a time; each may open an item, a category or a link.', ['n' => $maxBanners]) }}
            {{ __('Mark one "Show as soon as the menu opens" and it greets guests over the menu instead, after they choose how to order.') }}</p>
        @error('banners')<p class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror
        <form method="post" action="{{ route('dash.banners.autoplay') }}" class="card flex flex-wrap items-center justify-between gap-3" data-banner-autoplay-form>
            @csrf
            <label class="flex items-center gap-2 text-sm font-semibold">
                <input type="checkbox" name="banner_autoplay" value="1" @checked($settings['banner_autoplay'] !== '0') class="size-4 accent-brand-600">
                {{ __('Play the banners as a slideshow (every 4–5 seconds)') }}
            </label>
            <button class="btn-ghost min-h-9 px-3">{{ __('Save') }}</button>
            <p class="w-full text-xs text-navy-500">{{ __('It stops while the guest touches it. While the slideshow plays, the ticker stands still so only one thing moves.') }}</p>
        </form>
        @forelse ($banners as $b)
            <div @class(['card flex flex-wrap items-center justify-between gap-3', 'ring-2 ring-brand-200' => $b->is_popup && $b->is_active]) data-banner-row="{{ $b->id }}" @if ($b->is_popup) data-banner-popup @endif>
                <div class="flex items-center gap-3">
                    @if ($b->picture())
                        <img src="/storage/{{ $b->picture() }}" alt="" class="h-14 w-24 rounded-lg object-cover">
                    @else
                        <span class="grid h-14 w-24 place-items-center rounded-lg bg-navy-50 text-2xl" aria-hidden="true">📣</span>
                    @endif
                    <div>
                        <b>{{ $b->title ?: __('Banner') }}</b>
                        @if ($b->is_popup)<span class="badge bg-brand-100 text-brand-800">{{ __('Shows as soon as the menu opens') }}</span>@endif
                        @unless ($b->is_active)<span class="badge bg-navy-100 text-navy-500">{{ __('Paused') }}</span>@endunless
                        <div class="text-sm text-navy-500">
                            @if ($b->is_popup)
                                {{ ['session' => __('Once per visit'), 'day' => __('Once a day'), 'once' => __('Only once'), 'always' => __('Every time')][$b->popup_frequency] ?? __('Once per visit') }} ·
                                {{ __(':views views · :clicks clicks', ['views' => $b->views, 'clicks' => $b->clicks]) }} ·
                            @endif
                            @switch($b->linkType())
                                @case('item') → {{ $b->item?->name }} @break
                                @case('category') → {{ $b->category?->name }} @break
                                @case('url') → <span dir="ltr">{{ $b->link_url }}</span> @break
                                @default {{ __('No link') }}
                            @endswitch
                            @if ($b->starts_on || $b->ends_on) · {{ $b->starts_on?->format('Y/m/d') }} – {{ $b->ends_on?->format('Y/m/d') }}@endif
                        </div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <form method="post" action="{{ route('dash.banners.toggle', $b) }}">@csrf<button class="btn-ghost min-h-9 px-3">{{ $b->is_active ? __('Pause') : __('Resume') }}</button></form>
                    <form method="post" action="{{ route('dash.banners.delete', $b) }}" onsubmit="return confirm(@js(__('Delete this banner?')))">@csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Delete') }}</button></form>
                </div>
            </div>
        @empty
            <p class="empty-state">{{ __('No banners yet. A wide picture (1600×700) works best.') }}</p>
        @endforelse

        {{-- the ticker under the menu header (App\Support\MenuTicker): offers, cart goal and season come by themselves --}}
        <h3 class="section-title pt-4" id="ticker">{{ __('Menu ticker') }}</h3>
        <p class="muted -mt-2">{{ __('A thin line under the menu header that shows one short sentence at a time. Running offers, the cart goal and the season greeting are added by themselves; write up to :n lines of your own.', ['n' => \App\Support\MenuTicker::MAX_OWNER_LINES]) }}</p>
        {{-- one-line php statements only on this page: a php block would swallow the one-line ones above it --}}
        @php($tickerLines = \App\Support\MenuTicker::ownerLines($settings))
        <form method="post" action="{{ route('dash.ticker.update') }}" class="card space-y-3" data-ticker-form>
            @csrf
            @error('lines.*')<p class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror
            {{-- the filled lines and one empty one show; the rest wait behind "add a line" so the form stays short on phones --}}
            @php($tickerShown = min(\App\Support\MenuTicker::MAX_OWNER_LINES, count($tickerLines) + 1))
            @for ($n = 0; $n < \App\Support\MenuTicker::MAX_OWNER_LINES; $n++)
                @php($line = $tickerLines[$n] ?? ['text' => '', 'text_en' => '', 'link' => ''])
                @if ($n === $tickerShown)<details data-ticker-more><summary class="cursor-pointer py-2 text-sm font-bold text-navy-600">+ {{ __('Add another line') }}</summary><div class="space-y-3 pt-1">@endif
                <div class="grid gap-2 sm:grid-cols-3" data-ticker-row>
                    <input class="field" name="lines[{{ $n }}][text]" maxlength="{{ \App\Support\MenuTicker::MAX_LENGTH }}" value="{{ $line['text'] }}" placeholder="{{ $n === 0 ? __('e.g. Free delivery all week') : __('Line :n', ['n' => $n + 1]) }}">
                    <input class="field" name="lines[{{ $n }}][text_en]" maxlength="{{ \App\Support\MenuTicker::MAX_LENGTH }}" value="{{ $line['text_en'] }}" placeholder="{{ __('In English (optional)') }}" dir="ltr">
                    <select class="field" name="lines[{{ $n }}][link]" aria-label="{{ __('Opens when tapped') }}">
                        <option value="">{{ __('No link') }}</option>
                        <optgroup label="{{ __('An item') }}">
                            @foreach ($items as $i)<option value="item:{{ $i->id }}" @selected($line['link'] === 'item:' . $i->id)>{{ $i->name }}</option>@endforeach
                        </optgroup>
                        <optgroup label="{{ __('A category') }}">
                            @foreach ($categories as $c)<option value="category:{{ $c->id }}" @selected($line['link'] === 'category:' . $c->id)>{{ $c->name }}</option>@endforeach
                        </optgroup>
                    </select>
                </div>
            @endfor
            @if ($tickerShown < \App\Support\MenuTicker::MAX_OWNER_LINES)</div></details>@endif
            <button class="btn-primary">{{ __('Save ticker') }}</button>
        </form>
        </div>

        {{-- cart goal: "add X more and get Y free", applied by the server when the order is placed --}}
        <h2 class="section-title pt-4" id="cart-goal">{{ __('Cart goal') }}</h2>
        <p class="muted -mt-2">{{ __('A progress bar in the guest cart: once the order reaches the amount, the gift is added to the order by itself (table and online orders).') }}</p>
        <form method="post" action="{{ route('dash.cart-goal.update') }}" class="card grid gap-3 sm:grid-cols-2" data-goal-form>
            @csrf
            <label class="flex items-center gap-2 font-bold sm:col-span-2"><input type="checkbox" name="cart_goal_enabled" value="1" @checked($settings['cart_goal_enabled'] === '1') class="size-4 accent-brand-600"> {{ __('Show the cart goal on the menu') }}</label>
            <div><label class="field-label">{{ __('When the order reaches') }}</label><input class="field" name="cart_goal_amount" type="number" min="1" step="0.5" value="{{ (float) $settings['cart_goal_amount'] ?: '' }}" placeholder="200" data-goal-amount></div>
            <div><label class="field-label">{{ __('The guest gets') }}</label>
                <select class="field" name="cart_goal_reward" data-goal-reward>
                    <option value="item" @selected($settings['cart_goal_reward'] === 'item')>{{ __('A free item') }}</option>
                    <option value="percent" @selected($settings['cart_goal_reward'] === 'percent')>{{ __('A percent off') }}</option>
                    <option value="amount" @selected($settings['cart_goal_reward'] === 'amount')>{{ __('An amount off') }}</option>
                </select>
            </div>
            <div data-goal-for="item"><label class="field-label">{{ __('Free item') }}</label>
                <select class="field" name="cart_goal_item" data-goal-item><option value="">{{ __('Choose an item') }}</option>@foreach ($items as $i)<option value="{{ $i->id }}" @selected((string) $i->id === $settings['cart_goal_item'])>{{ $i->name }}</option>@endforeach</select>
            </div>
            <div data-goal-for="value"><label class="field-label">{{ __('Discount value') }}</label><input class="field" name="cart_goal_value" type="number" min="1" step="0.5" value="{{ (float) $settings['cart_goal_value'] ?: '' }}" data-goal-value></div>
            <div class="sm:col-span-2"><label class="field-label">{{ __('Text on the menu (written for you, you can edit it)') }}</label><input class="field" name="cart_goal_label" maxlength="120" value="{{ $settings['cart_goal_label'] }}" data-goal-label></div>
            <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" name="cart_goal_with_coupon" value="1" @checked($settings['cart_goal_with_coupon'] === '1') class="size-4 accent-brand-600"> {{ __('Also with a coupon code (off by default: a coupon replaces the gift)') }}</label>
            <div class="sm:col-span-2"><button class="btn-primary">{{ __('Save cart goal') }}</button></div>
        </form>


        {{-- seasonal looks of the menu --}}
        <h2 class="section-title pt-4" id="seasons">{{ __('Seasons') }}</h2>
        <p class="muted -mt-2">{{ __('Ramadan, Eid, Christmas or your own occasion: between two dates the menu shows a greeting, the season banner and logo, and its color.') }}</p>
        @forelse ($seasons as $season)
            <div class="card flex flex-wrap items-center justify-between gap-3" data-season="{{ $season->id }}">
                <div class="flex items-center gap-3">
                    <span class="grid size-12 place-items-center rounded-xl text-2xl" style="background: {{ $season->accent ?? '#F0F6FC' }}">{{ $season->icon() }}</span>
                    <div>
                        <b>{{ $season->name }}</b> <span class="badge bg-navy-100 text-navy-700">{{ \App\Models\Season::presetLabel($season->preset) }}</span>
                        @if ($liveSeason?->is($season))<span class="badge bg-emerald-100 text-emerald-800">{{ __('Running now') }}</span>@endif
                        @unless ($season->is_active)<span class="badge bg-navy-100 text-navy-500">{{ __('Paused') }}</span>@endunless
                        <div class="text-sm text-navy-500">{{ $season->starts_on->format('Y/m/d') }} – {{ $season->ends_on->format('Y/m/d') }}@if ($season->greeting) · {{ $season->greeting }}@endif</div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <form method="post" action="{{ route('dash.seasons.toggle', $season) }}">@csrf<button class="btn-ghost min-h-9 px-3">{{ $season->is_active ? __('Pause') : __('Resume') }}</button></form>
                    <form method="post" action="{{ route('dash.seasons.delete', $season) }}" onsubmit="return confirm(@js(__('Delete this season?')))">@csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Delete') }}</button></form>
                </div>
            </div>
        @empty
            <p class="empty-state">{{ __('No seasons yet. When two overlap, the one that starts last is shown.') }}</p>
        @endforelse
    </section>

    <div class="space-y-4 xl:col-span-2">
        <form method="post" action="{{ route('dash.offers.store') }}" class="card space-y-3">
            @csrf
            <h2 class="section-title">{{ __('New offer') }}</h2>
            <input class="field" name="title" required maxlength="80" placeholder="{{ __('e.g. Happy hour on cold drinks') }}">
            <div class="grid grid-cols-2 gap-2">
                <div><label class="field-label">{{ __('Discount %') }}</label><input class="field" name="discount_percent" type="number" min="1" max="90" required value="20"></div>
                <div><label class="field-label">{{ __('On') }}</label>
                    <select class="field" name="category_id"><option value="">{{ __('Whole menu') }}</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                </div>
                <div><label class="field-label">{{ __('From') }}</label><input class="field" name="starts_at" type="time"></div>
                <div><label class="field-label">{{ __('To') }}</label><input class="field" name="ends_at" type="time"></div>
            </div>
            <div class="flex flex-wrap gap-x-3 gap-y-1">
                @foreach ($days as $i => $d)
                    <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="days[]" value="{{ $i }}" checked class="size-4 accent-brand-600"> {{ $d }}</label>
                @endforeach
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="field-label">{{ __('Starts on (optional)') }}</label><input class="field" name="starts_on" type="date"></div>
                <div><label class="field-label">{{ __('Ends on (optional)') }}</label><input class="field" name="ends_on" type="date"></div>
            </div>
            @if ($branches->count() > 1)
                <select class="field" name="branch_id"><option value="">{{ __('All branches') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
            @endif
            <button class="btn-primary w-full">{{ __('Save offer') }}</button>
        </form>

        <form method="post" action="{{ route('dash.banners.store') }}" enctype="multipart/form-data" class="card space-y-3">
            @csrf
            <h2 class="section-title">{{ __('New banner') }}</h2>
            <input class="field py-2" type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
            <div class="grid grid-cols-2 gap-2">
                <input class="field" name="title" maxlength="80" placeholder="{{ __('Title (optional)') }}">
                <input class="field" name="title_en" maxlength="80" placeholder="{{ __('Title (English)') }}" dir="ltr">
            </div>
            <select class="field" name="link_type" data-banner-link>
                <option value="none">{{ __('No link') }}</option>
                <option value="item">{{ __('Opens a menu item') }}</option>
                <option value="category">{{ __('Jumps to a category') }}</option>
                <option value="url">{{ __('Opens a link') }}</option>
            </select>
            <select class="field hidden" name="menu_item_id" data-banner-for="item"><option value="">{{ __('Choose an item') }}</option>@foreach ($items as $i)<option value="{{ $i->id }}">{{ $i->name }}</option>@endforeach</select>
            <select class="field hidden" name="category_id" data-banner-for="category"><option value="">{{ __('Choose a category') }}</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
            <input class="field hidden" name="link_url" placeholder="https://…" dir="ltr" data-banner-for="url">
            <div class="grid grid-cols-2 gap-2">
                <div><label class="field-label">{{ __('Starts on (optional)') }}</label><input class="field" name="starts_on" type="date"></div>
                <div><label class="field-label">{{ __('Ends on (optional)') }}</label><input class="field" name="ends_on" type="date"></div>
            </div>
            {{-- the popup is a banner too: it opens over the menu (after the first-open sheet), one at a time --}}
            <div class="rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50" data-banner-popup-box>
                <label class="flex items-start gap-2 text-sm font-semibold">
                    <input type="checkbox" name="is_popup" value="1" class="mt-0.5 size-4 accent-brand-600" data-banner-popup>
                    <span>{{ __('Show as soon as the menu opens') }}
                        <span class="block text-xs font-normal text-navy-500">{{ __('Instead of the strip, it opens over the menu once. Only one at a time: it replaces the one that was on.') }}</span></span>
                </label>
                <div class="mt-2 hidden" data-banner-popup-for>
                    <label class="field-label" for="popup_frequency">{{ __('Show') }}</label>
                    <select class="field" name="popup_frequency" id="popup_frequency">
                        <option value="session">{{ __('Once per visit') }}</option>
                        <option value="day">{{ __('Once a day') }}</option>
                        <option value="once">{{ __('Only once') }}</option>
                        <option value="always">{{ __('Every time') }}</option>
                    </select>
                </div>
            </div>
            <button class="btn-primary w-full">{{ __('Save banner') }}</button>
        </form>

        <form method="post" action="{{ route('dash.seasons.store') }}" enctype="multipart/form-data" class="card space-y-3" data-season-form>
            @csrf
            <h2 class="section-title">{{ __('New season') }}</h2>
            <select class="field" name="preset" data-season-preset>
                @foreach (\App\Models\Season::PRESETS as $key => $preset)
                    <option value="{{ $key }}" data-greeting="{{ $preset['greeting'] }}" data-greeting-en="{{ $preset['greeting_en'] }}" data-accent="{{ $preset['accent'] }}">{{ $preset['icon'] }} {{ \App\Models\Season::presetLabel($key) }}</option>
                @endforeach
            </select>
            <input class="field" name="name" required maxlength="80" placeholder="{{ __('Name, e.g. Ramadan 2027') }}" data-season-name>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="field-label">{{ __('From') }}</label><input class="field" name="starts_on" type="date" required></div>
                <div><label class="field-label">{{ __('To') }}</label><input class="field" name="ends_on" type="date" required></div>
            </div>
            <input class="field" name="greeting" maxlength="120" placeholder="{{ __('Greeting line') }}" data-season-greeting>
            <input class="field" name="greeting_en" maxlength="120" placeholder="{{ __('Greeting (English)') }}" dir="ltr" data-season-greeting-en>
            <div class="flex items-center gap-3">
                <label class="field-label mb-0">{{ __('Season color') }}</label>
                <input type="color" name="accent" value="#0F6B4F" class="h-10 w-16 rounded-lg border border-navy-100" data-season-accent>
            </div>
            <div><label class="field-label">{{ __('Season banner (optional, replaces the cover)') }}</label><input class="field py-2" type="file" name="banner" accept="image/jpeg,image/png,image/webp"></div>
            <div><label class="field-label">{{ __('Season logo (optional)') }}</label><input class="field py-2" type="file" name="logo" accept="image/png,image/webp,image/jpeg"></div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="decoration" value="1" checked class="size-4 accent-brand-600"> {{ __('Light decoration (crescents, lanterns, snow…)') }}</label>
            <button class="btn-primary w-full">{{ __('Save season') }}</button>
        </form>
    </div>
</div>
<script>
    (() => {
        const popupBox = document.querySelector('[data-banner-popup]');
        const syncPopup = () => document.querySelector('[data-banner-popup-for]').classList.toggle('hidden', !popupBox.checked);
        popupBox.addEventListener('change', syncPopup);
        syncPopup();
        const link = document.querySelector('[data-banner-link]');
        const syncLink = () => document.querySelectorAll('[data-banner-for]').forEach((el) => el.classList.toggle('hidden', el.dataset.bannerFor !== link.value));
        link.addEventListener('change', syncLink);
        syncLink();

        // cart goal: item or discount fields, and the generated text until the owner writes their own
        const goal = document.querySelector('[data-goal-form]');
        const reward = goal.querySelector('[data-goal-reward]');
        const labelField = goal.querySelector('[data-goal-label]');
        const texts = {{ \Illuminate\Support\Js::from(['item' => __('Order for :amount and get :reward free'), 'off' => __('Order for :amount and get :reward off'), 'currency' => tr($settings['currency'], $settings['currency_en'])]) }};
        let generated = labelField.value === '';
        const autoText = () => {
            const amount = `${goal.querySelector('[data-goal-amount]').value || '…'} ${texts.currency}`;
            const value = goal.querySelector('[data-goal-value]').value || '…';
            const item = goal.querySelector('[data-goal-item]');
            const what = reward.value === 'item' ? (item.value ? item.selectedOptions[0].text : '…') : reward.value === 'percent' ? `${value}%` : `${value} ${texts.currency}`;
            return (reward.value === 'item' ? texts.item : texts.off).replace(':amount', amount).replace(':reward', what);
        };
        const syncGoal = () => {
            goal.querySelector('[data-goal-for="item"]').classList.toggle('hidden', reward.value !== 'item');
            goal.querySelector('[data-goal-for="value"]').classList.toggle('hidden', reward.value === 'item');
            if (generated) labelField.placeholder = autoText();
        };
        goal.addEventListener('input', (e) => { if (e.target === labelField) generated = labelField.value === ''; syncGoal(); });
        goal.addEventListener('change', syncGoal);
        syncGoal();

        // season presets fill the greeting and color
        const seasonForm = document.querySelector('[data-season-form]');
        const preset = seasonForm.querySelector('[data-season-preset]');
        const applyPreset = () => {
            const o = preset.selectedOptions[0];
            seasonForm.querySelector('[data-season-greeting]').value = o.dataset.greeting;
            seasonForm.querySelector('[data-season-greeting-en]').value = o.dataset.greetingEn;
            if (o.dataset.accent) seasonForm.querySelector('[data-season-accent]').value = o.dataset.accent;
            const name = seasonForm.querySelector('[data-season-name]');
            if (!name.value && o.dataset.greeting) name.value = `${o.text.replace(/^\S+\s/, '')} ${new Date().getFullYear()}`;
        };
        preset.addEventListener('change', applyPreset);
        applyPreset();
    })();
</script>
@endsection
