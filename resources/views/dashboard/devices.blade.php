@extends('layouts.dashboard')
@section('title', __('Screens & devices'))

@section('content')
@use('App\Http\Controllers\Dashboard\DevicesController')
@php
    $label = fn (string $type) => DevicesController::label($type);
    $tabs = [['all', __('All'), route('dash.devices')]];
    foreach ($types as $type => $unlocked) {
        $tabs[] = [$type, $label($type), route('dash.devices', ['tab' => $type]), isset($counts[$type]) && $unlocked ? $counts[$type] : null];
    }
    $shows = fn (string $type) => isset($types[$type]) && ($tab === 'all' || $tab === $type);
    $confirmLink = __('The current link will stop working. Continue?');
@endphp
<x-dash.page-header :title="__('Screens & devices')" :help="__('Every screen you open on a device: TV menus, the kiosk tablet, the customer display and the kitchen screen. Scan the QR with the device or open its link once and leave it.')" />

@include('dashboard.partials.tabs', ['tabs' => $tabs, 'current' => $tab, 'label' => __('Screens & devices')])

<div class="space-y-6" data-devices-tab="{{ $tab }}">
    @foreach ($types as $type => $unlocked)
        @continue(! $shows($type))
        @if (! $unlocked)
            {{-- the plan has no such device: one locked card pointing to the plan --}}
            <section class="card flex flex-wrap items-center gap-4 border-dashed" data-device-locked="{{ $type }}">
                <span class="grid size-12 shrink-0 place-items-center rounded-full bg-brand-100 text-brand-700">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="section-title">{{ $label($type) }}</h2>
                    <p class="text-sm text-navy-500">{{ \App\Services\Entitlements::current()->upgradeMessage(DevicesController::TYPES[$type][1]) }}</p>
                </div>
                <a href="{{ route('dash.billing') }}" class="btn-primary">{{ __('See plans and add-ons') }}</a>
            </section>
            @continue
        @endif

        @if ($type === 'tv')
            <section class="space-y-3" id="tv">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="section-title">{{ __('TV screens') }}</h2>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('dash.tv.pair') }}" class="btn-primary min-h-9 px-3" data-pair-tv>{{ __('Pair a TV') }}</a>
                        @if ($tab === 'all')<a href="{{ route('dash.devices', ['tab' => 'tv']) }}#add-tv" class="btn-ghost min-h-9 px-3">＋ {{ __('Add screen') }}</a>@endif
                    </div>
                </div>
                <p class="text-sm text-navy-500">{!! __('On the TV open :url (any smart TV browser, a TV box or the EgHub app): it shows a code you type here. Each screen plays its own playlist: the menu with live prices, pictures, video, offers and ready orders, and keeps playing when the internet drops.', ['url' => '<b dir="ltr">'.e(preg_replace('#^https?://#', '', platform_url(null, 'tv'))).'</b>']) !!}</p>
                @if ($tab === 'tv')
                    <div class="max-w-md">@include('dashboard.partials.tv-limit', ['limit' => $screenLimit, 'paired' => $pairedScreens])</div>
                @endif
                <div @class(['grid gap-4', 'xl:grid-cols-3' => $tab === 'tv', 'md:grid-cols-2 2xl:grid-cols-3' => $tab !== 'tv'])>
                    <div @class(['grid content-start gap-4 md:grid-cols-2 xl:col-span-2' => $tab === 'tv', 'contents' => $tab !== 'tv'])>
                        @forelse ($screens as $s)
                            <x-dash.device-card type="tv" :label="__('TV screen')" :name="$s->name" :url="$s->url()" :qr="$qrs['tv'][$s->id]" :inactive="! $s->is_active" :open="__('Preview')"
                                :meta="($s->slides_count ? trans_choice(':count slide|:count slides', $s->slides_count) : __('Whole menu')) . ($s->branch ? ' · ' . tr($s->branch->name, $s->branch->name_en) : '')">
                                @include('dashboard.partials.tv-status', ['screen' => $s])
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('dash.tv.edit', $s) }}" class="btn-primary min-h-9 px-3" data-edit-playlist>{{ __('Edit playlist') }}</a>
                                    @unless ($s->isPaired())<a href="{{ route('dash.tv.pair', ['screen' => $s->id]) }}" class="btn-ghost min-h-9 px-3">{{ __('Pair a TV') }}</a>@endunless
                                </div>
                                <details>
                                    <summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ __('Whole-menu settings') }}</summary>
                                    <form method="post" action="{{ route('dash.tv.update', $s) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                        @csrf
                                        @include('dashboard.partials.tv-fields', ['s' => $s])
                                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($s->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                                        <div class="sm:col-span-2"><button class="btn-primary">{{ __('Save') }}</button></div>
                                    </form>
                                </details>
                                <div class="flex flex-wrap gap-3 border-t border-navy-100 pt-2 text-sm">
                                    <form method="post" action="{{ route('dash.tv.regenerate', $s) }}" onsubmit="return confirm(@js($confirmLink))">@csrf<button class="min-h-10 px-1 text-navy-600 underline" data-regenerate>{{ __('New link') }}</button></form>
                                    <form method="post" action="{{ route('dash.tv.delete', $s) }}" onsubmit="return confirm(@js(__('Delete this screen?')))">@csrf<button class="min-h-10 px-1 text-red-600">{{ __('Delete') }}</button></form>
                                </div>
                            </x-dash.device-card>
                        @empty
                            <div class="empty-state md:col-span-2">{{ __('No screens yet. Add your first TV screen.') }}</div>
                        @endforelse
                    </div>
                    @if ($tab === 'tv')
                        <form method="post" action="{{ route('dash.tv.store') }}" class="card grid h-fit gap-3" id="add-tv">
                            @csrf
                            <h2 class="section-title">{{ __('Add screen') }}</h2>
                            @include('dashboard.partials.tv-fields', ['s' => null])
                            <button class="btn-primary">{{ __('Add screen') }}</button>
                        </form>
                    @endif
                </div>
            </section>
        @elseif ($type === 'kiosk')
            <section class="space-y-3" id="kiosk">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="section-title">{{ __('Kiosk') }}</h2>
                    @if ($tab === 'all')<a href="{{ route('dash.devices', ['tab' => 'kiosk']) }}#add-kiosk" class="btn-ghost min-h-9 px-3">＋ {{ __('Add kiosk') }}</a>@endif
                </div>
                <p class="text-sm text-navy-500">{{ __('Put a tablet at the counter and open the kiosk link on it once. Guests order by themselves, get their order number and pay at the cashier; the order shows on the order board and the kitchen screen right away.') }}</p>
                <div @class(['grid gap-4', 'xl:grid-cols-3' => $tab === 'kiosk', 'md:grid-cols-2 2xl:grid-cols-3' => $tab !== 'kiosk'])>
                    <div @class(['grid content-start gap-4 md:grid-cols-2 xl:col-span-2' => $tab === 'kiosk', 'contents' => $tab !== 'kiosk'])>
                        @forelse ($kiosks as $k)
                            @php
                                $o = $k->options();
                            @endphp
                            <x-dash.device-card type="kiosk" :label="__('Kiosk')" :name="$k->name" :url="$k->url()" :qr="$qrs['kiosk'][$k->id]" :inactive="! $k->is_active" :open="__('Open kiosk')"
                                :meta="tr($k->branch?->name, $k->branch?->name_en) . ' · ' . collect($o['order_types'])->map(fn ($t) => $t === 'takeaway' ? __('Takeaway') : __('Dine-in'))->implode(' / ')">
                                <details>
                                    <summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ __('Rename and settings') }}</summary>
                                    <form method="post" action="{{ route('dash.kiosks.update', $k) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                        @csrf
                                        @include('dashboard.partials.kiosk-fields', ['k' => $k, 'branches' => $activeBranches])
                                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($k->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                                        <div class="sm:col-span-2"><button class="btn-primary">{{ __('Save') }}</button></div>
                                    </form>
                                </details>
                                <div class="flex flex-wrap gap-3 border-t border-navy-100 pt-2 text-sm">
                                    <form method="post" action="{{ route('dash.kiosks.regenerate', $k) }}" onsubmit="return confirm(@js($confirmLink))">@csrf<button class="min-h-10 px-1 text-navy-600 underline" data-regenerate>{{ __('New link') }}</button></form>
                                    <form method="post" action="{{ route('dash.kiosks.delete', $k) }}" onsubmit="return confirm(@js(__('Delete this kiosk?')))">@csrf<button class="min-h-10 px-1 text-red-600">{{ __('Delete') }}</button></form>
                                </div>
                            </x-dash.device-card>
                        @empty
                            <div class="empty-state md:col-span-2">{{ __('No kiosks yet. Add your first kiosk.') }}</div>
                        @endforelse
                    </div>
                    @if ($tab === 'kiosk')
                        <form method="post" action="{{ route('dash.kiosks.store') }}" class="card grid h-fit gap-3" id="add-kiosk">
                            @csrf
                            <h2 class="section-title">{{ __('Add kiosk') }}</h2>
                            @include('dashboard.partials.kiosk-fields', ['k' => null, 'branches' => $activeBranches])
                            <button class="btn-primary">{{ __('Add kiosk') }}</button>
                        </form>
                    @endif
                </div>
            </section>
        @elseif ($type === 'display')
            <section class="space-y-3" id="display">
                <h2 class="section-title">{{ __('Customer display & order board') }}</h2>
                <p class="text-sm text-navy-500">{{ __('The order-number board shows only numbers (preparing and ready) on a screen in the hall. The customer display shows the cashier\'s order to the guest; open it from the POS on a second screen.') }}</p>
                <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    @foreach ($boards as $board)
                        <x-dash.device-card type="board" :label="__('Order-number board')" :name="$board['name']" :url="$board['url']" :qr="$board['qr']" :open="__('Open board')" />
                    @endforeach
                    @if ($posDisplay)
                        <section class="card flex flex-col gap-3" data-device="customer-display">
                            <span class="badge w-fit bg-navy-50 text-navy-600">{{ __('Customer display') }}</span>
                            <h3 class="text-base font-bold sm:text-lg">{{ __('Screen facing the guest at the cashier') }}</h3>
                            <p class="text-sm text-navy-500">{{ __('Open the POS on the cashier computer and press "Customer display"; drag the window onto the second screen.') }}</p>
                            <div class="mt-auto flex flex-wrap gap-2">
                                <a href="{{ route('dash.pos') }}" class="btn-primary min-h-9 px-3">{{ __('Go to POS') }}</a>
                                <a href="{{ $posDisplay }}" target="_blank" rel="noopener" class="btn-ghost min-h-9 px-3">{{ __('Open customer display') }}</a>
                            </div>
                        </section>
                    @endif
                </div>
            </section>
        @elseif ($type === 'kitchen')
            <section class="space-y-3" id="kitchen">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="section-title">{{ $label('kitchen') }}</h2>
                    @if ($stationsUrl)<a href="{{ $stationsUrl }}" class="btn-ghost min-h-9 px-3">⚙ {{ __('Kitchen stations') }}</a>@endif
                </div>
                <p class="text-sm text-navy-500">{{ __('Sign in on the kitchen tablet with a kitchen account and open the screen; with stations, each station opens its own link.') }}</p>
                <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    @foreach ($kitchen as $screen)
                        <x-dash.device-card type="kitchen" :label="$label('kitchen')" :name="$screen['name']" :url="$screen['url']" :qr="$screen['qr']" :open="__('Open screen')" />
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
</div>

<script>
    document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = @js(__('Copied')); } catch (e) { prompt('', b.dataset.copy); }
    }));
</script>
@endsection
