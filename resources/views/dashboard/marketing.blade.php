@extends('layouts.dashboard')
@section('title', __('Offers & popups'))

@section('content')
@php
    $days = [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')];
    $liveBanners = $banners->filter(fn ($b) => $b->is_active && (! $b->ends_on || $b->ends_on->gte(today())))->count();
@endphp
<h1 class="page-title mb-1">{{ __('Offers & popups') }}</h1>
<p class="muted mb-4">{{ __('Offers lower prices automatically on the menu and at the cashier during their hours.') }}</p>

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
            <p class="card muted">{{ __('No offers yet. Example: 20% off cold drinks every day 3–6 PM.') }}</p>
        @endforelse

        <h2 class="section-title pt-4">{{ __('Popups') }}</h2>
        @forelse ($popups as $p)
            <div class="card flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    @if ($p->image)<img src="/storage/{{ $p->image }}" alt="" class="size-14 rounded-lg object-cover">@endif
                    <div>
                        <b>{{ $p->title ?: $p->item?->name }}</b>
                        @unless ($p->is_active)<span class="badge bg-navy-100 text-navy-500">{{ __('Paused') }}</span>@endunless
                        <div class="text-sm text-navy-500">{{ __(':views views · :clicks clicks', ['views' => $p->views, 'clicks' => $p->clicks]) }}</div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <form method="post" action="{{ route('dash.popups.toggle', $p) }}">@csrf<button class="btn-ghost min-h-9 px-3">{{ $p->is_active ? __('Pause') : __('Resume') }}</button></form>
                    <form method="post" action="{{ route('dash.popups.delete', $p) }}" onsubmit="return confirm(@js(__('Delete this popup?')))">@csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Delete') }}</button></form>
                </div>
            </div>
        @empty
            <p class="card muted">{{ __('No popups yet. A popup greets guests when they open the menu.') }}</p>
        @endforelse

        <h2 class="section-title pt-4">{{ __('Menu banners') }} <span class="badge bg-navy-100 text-navy-700">{{ $liveBanners }} / {{ $maxBanners }}</span></h2>
        <p class="muted -mt-2">{{ __('A swipeable strip of pictures under the menu header. Up to :n active at a time; each may open an item, a category or a link.', ['n' => $maxBanners]) }}</p>
        @error('banners')<p class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror
        @forelse ($banners as $b)
            <div class="card flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <img src="/storage/{{ $b->image }}" alt="" class="h-14 w-24 rounded-lg object-cover">
                    <div>
                        <b>{{ $b->title ?: __('Banner') }}</b>
                        @unless ($b->is_active)<span class="badge bg-navy-100 text-navy-500">{{ __('Paused') }}</span>@endunless
                        <div class="text-sm text-navy-500">
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
            <p class="card muted">{{ __('No banners yet. A wide picture (1600×700) works best.') }}</p>
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

        <form method="post" action="{{ route('dash.popups.store') }}" enctype="multipart/form-data" class="card space-y-3">
            @csrf
            <h2 class="section-title">{{ __('New popup') }}</h2>
            <select class="field" name="type" data-popup-type>
                <option value="image">{{ __('Picture with a link') }}</option>
                <option value="item">{{ __('Promote a menu item') }}</option>
            </select>
            <input class="field" name="title" maxlength="80" placeholder="{{ __('Title') }}">
            <textarea class="field min-h-16 py-2" name="body" maxlength="300" placeholder="{{ __('Text (optional)') }}"></textarea>
            <div data-for="image" class="space-y-2">
                <input class="field py-2" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                <input class="field" name="link_url" placeholder="https://… ({{ __('optional') }})" dir="ltr">
            </div>
            <div data-for="item" class="hidden">
                <select class="field" name="menu_item_id"><option value="">{{ __('Choose an item') }}</option>@foreach ($items as $i)<option value="{{ $i->id }}">{{ $i->name }}</option>@endforeach</select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="field-label">{{ __('Show') }}</label>
                    <select class="field" name="frequency">
                        <option value="session">{{ __('Once per visit') }}</option>
                        <option value="day">{{ __('Once a day') }}</option>
                        <option value="once">{{ __('Only once') }}</option>
                        <option value="always">{{ __('Every time') }}</option>
                    </select>
                </div>
                <div><label class="field-label">{{ __('After (seconds)') }}</label><input class="field" name="delay_seconds" type="number" min="0" max="30" value="2"></div>
                <div><label class="field-label">{{ __('Starts on (optional)') }}</label><input class="field" name="starts_on" type="date"></div>
                <div><label class="field-label">{{ __('Ends on (optional)') }}</label><input class="field" name="ends_on" type="date"></div>
            </div>
            <button class="btn-primary w-full">{{ __('Save popup') }}</button>
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
            <button class="btn-primary w-full">{{ __('Save banner') }}</button>
        </form>
    </div>
</div>
<script>
    (() => {
        const sel = document.querySelector('[data-popup-type]');
        const sync = () => document.querySelectorAll('[data-for]').forEach((el) => el.classList.toggle('hidden', el.dataset.for !== sel.value));
        sel.addEventListener('change', sync);
        sync();
        const link = document.querySelector('[data-banner-link]');
        const syncLink = () => document.querySelectorAll('[data-banner-for]').forEach((el) => el.classList.toggle('hidden', el.dataset.bannerFor !== link.value));
        link.addEventListener('change', syncLink);
        syncLink();
    })();
</script>
@endsection
