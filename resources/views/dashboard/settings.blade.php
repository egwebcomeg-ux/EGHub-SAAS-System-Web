@extends('layouts.dashboard')
@section('title', __('Settings'))

@section('content')
<x-dash.page-header :title="__('Settings')" :help="__('Your restaurant identity, menu design, ordering and messages.')">
    <x-slot:actions>
        <a href="{{ route('dash.modules') }}" class="btn-ghost" data-settings-modules>🧩 {{ __('Modules') }}</a>
    </x-slot:actions>
</x-dash.page-header>

@include('dashboard.partials.tabs', ['label' => __('Settings'), 'current' => $tab, 'attr' => 'data-settings-tab', 'tabs' => collect($tabs)->map(fn ($label, $key) => [$key, $label, route('dash.settings', ['tab' => $key])])->values()->all()])

<form method="post" action="{{ route('dash.settings.update') }}" enctype="multipart/form-data" class="space-y-4"
    data-unsaved-guard="{{ __('You have changes that are not saved. Leave without saving?') }}" data-save-shortcut>
    @csrf
    <input type="hidden" name="settings_tab" value="{{ $tab }}" data-settings-tab-input>
    <div class="space-y-4" data-settings-panel="identity" @if ($tab !== 'identity') hidden @endif>
        <section class="card grid gap-4 sm:grid-cols-2">
            <h2 class="text-lg font-bold sm:col-span-2">{{ __('Restaurant identity') }}</h2>
            <div class="sm:col-span-2">
                <label class="field-label">{{ __('Type of place') }}</label>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Support\BusinessType::TYPES as $key => $label)
                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-navy-100 px-4 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="business_type" value="{{ $key }}" class="accent-brand-600" @checked(old('business_type', $s['business_type']) === $key)> {{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-navy-500">{{ __('It changes the names on your screens (bar or kitchen) and the suggestions you see. Every feature stays available.') }}</p>
            </div>
            <div><label class="field-label">{{ __('Name (Arabic)') }}</label><input class="field" name="brand_name" value="{{ old('brand_name', $s['brand_name']) }}" required maxlength="80"></div>
            <div><label class="field-label">{{ __('Name (English)') }}</label><input class="field" name="brand_name_en" value="{{ old('brand_name_en', $s['brand_name_en']) }}" maxlength="80" dir="ltr"></div>
            <div><label class="field-label">{{ __('Tagline (Arabic)') }}</label><input class="field" name="tagline" value="{{ old('tagline', $s['tagline']) }}" maxlength="150"></div>
            <div><label class="field-label">{{ __('Tagline (English)') }}</label><input class="field" name="tagline_en" value="{{ old('tagline_en', $s['tagline_en']) }}" maxlength="150" dir="ltr"></div>
            @foreach (['logo' => __('Logo'), 'cover' => __('Cover image')] as $key => $label)
                <div>
                    <label class="field-label">{{ $label }}</label>
                    @if ($s[$key])
                        <div class="mb-2 flex items-center gap-3">
                            <img src="/storage/{{ $s[$key] }}" alt="" class="h-14 rounded-lg border border-navy-100 object-contain">
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_{{ $key }}" value="1" class="size-4 accent-brand-600"> {{ __('Remove') }}</label>
                        </div>
                    @endif
                    <input class="field py-2" type="file" name="{{ $key }}" accept="image/jpeg,image/png,image/webp">
                    @if ($key === 'logo')
                        <label class="field-label mt-2" for="logo_background">{{ __('Behind the logo') }}</label>
                        <select class="field" name="logo_background" id="logo_background">
                            @foreach (['auto' => __('Automatic: no background on dark themes, white on light ones'), 'white' => __('Always a white tile'), 'none' => __('No background (transparent logo)')] as $value => $text)
                                <option value="{{ $value }}" @selected($s['logo_background'] === $value)>{{ $text }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-navy-500">{{ __('A transparent PNG keeps its edges sharp on every theme.') }}</p>
                    @endif
                </div>
            @endforeach
        </section>

        <section class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <h2 class="text-lg font-bold sm:col-span-2 lg:col-span-4">{{ __('Colors & font') }}</h2>
            @unless ($branding)
                <p class="rounded-xl bg-brand-50 p-3 text-sm text-brand-700 sm:col-span-2 lg:col-span-4">{{ \App\Services\Entitlements::current()->upgradeMessage('full_branding') }}</p>
            @endunless
            @foreach (['color_primary' => __('Main color'), 'color_secondary' => __('Header color'), 'color_bg' => __('Background color')] as $key => $label)
                <div>
                    <label class="field-label">{{ $label }}</label>
                    <input class="field h-11 p-1" type="color" name="{{ $key }}" value="{{ old($key, $s[$key]) }}" @disabled(!$branding)>
                    @unless ($branding)<input type="hidden" name="{{ $key }}" value="{{ $s[$key] }}">@endunless
                </div>
            @endforeach
            <div>
                <label class="field-label">{{ __('Font') }}</label>
                <select class="field" name="font" @disabled(!$branding)>
                    @foreach ($fonts as $font)
                        <option value="{{ $font }}" @selected($s['font'] === $font)>{{ $font }}</option>
                    @endforeach
                </select>
                @unless ($branding)<input type="hidden" name="font" value="{{ $s['font'] }}">@endunless
            </div>
        </section>

        <section class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <h2 class="text-lg font-bold sm:col-span-2 lg:col-span-3">{{ __('Contact & social') }}</h2>
            <div><label class="field-label">{{ __('Phone') }}</label><input class="field" name="phone" value="{{ $s['phone'] }}" dir="ltr"></div>
            <div><label class="field-label">{{ __('WhatsApp') }}</label><input class="field" name="whatsapp" value="{{ $s['whatsapp'] }}" dir="ltr"></div>
            <div><label class="field-label">Instagram</label><input class="field" name="instagram" value="{{ $s['instagram'] }}" dir="ltr" placeholder="https://instagram.com/..."></div>
            <div><label class="field-label">Facebook</label><input class="field" name="facebook" value="{{ $s['facebook'] }}" dir="ltr" placeholder="https://facebook.com/..."></div>
            <div><label class="field-label">TikTok</label><input class="field" name="tiktok" value="{{ $s['tiktok'] }}" dir="ltr"></div>
            <div><label class="field-label">{{ __('Google review link') }}</label><input class="field" name="google_review_url" value="{{ $s['google_review_url'] }}" dir="ltr"></div>
        </section>

        <section class="card grid gap-4 sm:grid-cols-2" id="seo">
            <div class="sm:col-span-2">
                <h2 class="text-lg font-bold">{{ __('Showing up in search') }}</h2>
                <p class="text-sm text-navy-500">{{ __('Used on your page :url, in the EgMenu restaurant directory and by Google.', ['url' => tenant()->url('about')]) }}</p>
            </div>
            <div>
                <label class="field-label" for="city">{{ __('City') }}</label>
                <select class="field" id="city" name="city">
                    <option value="">—</option>
                    @foreach (\App\Support\Cities::options() as $slug => $name)
                        <option value="{{ $slug }}" @selected(old('city', $s['city']) === $slug)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="area">{{ __('Area') }}</label>
                <input class="field" id="area" name="area" value="{{ old('area', $s['area']) }}" maxlength="60" placeholder="{{ __('e.g. Maadi') }}">
            </div>
            <div class="sm:col-span-2">
                <span class="field-label">{{ __('What you serve (up to 6)') }}</span>
                @php
                    $chosen = old('cuisine', \App\Support\Cuisines::parse($s['cuisine']));
                @endphp
                <div class="flex flex-wrap gap-2">
                    @foreach (array_keys(\App\Support\Cuisines::ALL) as $key)
                        <label class="flex min-h-10 cursor-pointer items-center gap-2 rounded-xl border border-navy-100 px-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="checkbox" name="cuisine[]" value="{{ $key }}" class="accent-brand-600" @checked(in_array($key, $chosen, true))> {{ \App\Support\Cuisines::name($key) }}
                        </label>
                    @endforeach
                </div>
                @error('cuisine')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="field-label" for="short_description">{{ __('Short description (Arabic)') }}</label>
                <textarea class="field min-h-20 py-2" id="short_description" name="short_description" maxlength="160">{{ old('short_description', $s['short_description']) }}</textarea>
            </div>
            <div>
                <label class="field-label" for="short_description_en">{{ __('Short description (English)') }}</label>
                <textarea class="field min-h-20 py-2" id="short_description_en" name="short_description_en" maxlength="160" dir="ltr">{{ old('short_description_en', $s['short_description_en']) }}</textarea>
            </div>
            <p class="text-xs text-navy-500 sm:col-span-2">{{ __('Up to 160 characters: what makes your place worth a visit. It appears under your name in Google.') }}</p>
            <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-2"><input type="checkbox" name="listed_in_directory" value="1" @checked($s['listed_in_directory'] === '1') class="size-4 accent-brand-600"> {{ __('Show my place in the EgMenu restaurant directory') }}</label>
        </section>

        {{-- the place's page (/about; /links shows the same page): its links on top, then the description, contact and branches above --}}
        <section class="card grid gap-4 sm:grid-cols-2" id="links-page" data-about-page>
            <div class="sm:col-span-2">
                <h2 class="text-lg font-bold">{{ __('Your place page & QR') }}</h2>
                <p class="text-sm text-navy-500">{{ __('One page for your place: the buttons on top (the menu, ordering, booking, WhatsApp, your social accounts), then your description, branches, hours and the map. It uses the contact, social and description fields above.') }}
                    <a href="{{ tenant()->url('about') }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 underline" dir="ltr">{{ tenant()->url('about') }}</a></p>
            </div>
            <div class="sm:col-span-2">
                <span class="field-label">{{ __('When a guest scans your QR, open') }}</span>
                <div class="flex flex-wrap gap-2">
                    @foreach (['menu' => [__('The menu'), __('Straight to the items (default)')], 'links' => [__('Your place page'), __('The buttons first, the menu one tap away')]] as $key => [$label, $hint])
                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-navy-100 px-4 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="qr_target" value="{{ $key }}" class="accent-brand-600" @checked(old('qr_target', $s['qr_target']) === $key)>
                            <span><span class="block text-sm font-semibold">{{ $label }}</span><span class="block text-xs text-navy-500">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-navy-500">{{ __('Applies to the table QR cards already printed and to the storefront QR you print next.') }}</p>
            </div>
        </section>
    </div>

    <div class="space-y-4" data-settings-panel="menu" @if ($tab !== 'menu') hidden @endif>
        <section class="card space-y-3">
            <h2 class="text-lg font-bold">{{ __('Menu design') }}</h2>
            @unless ($themes)
                <p class="rounded-xl bg-brand-50 p-3 text-sm text-brand-700">{{ \App\Services\Entitlements::current()->upgradeMessage('menu_themes') }}</p>
            @endunless
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach (\App\Support\MenuThemes::keys() as $key)
                    @php
                        [$label, $hint] = \App\Support\MenuThemes::label($key);
                    @endphp
                    <label @class(['cursor-pointer rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50', 'opacity-50' => !$themes && $key !== 'classic'])>
                        <input type="radio" name="menu_theme" value="{{ $key }}" class="sr-only" @checked(($themes ? $s['menu_theme'] : 'classic') === $key) @disabled(!$themes && $key !== 'classic')>
                        @include('dashboard.partials.theme-thumb', ['theme' => $key])
                        <span class="mt-2 flex items-center gap-1.5 font-bold">{{ $label }}@if (\App\Support\MenuThemes::suits($key, $s['business_type']) && ! in_array($key, ['classic', 'grid'], true))<span class="badge bg-emerald-100 text-emerald-800">{{ __('Suits you') }}</span>@endif</span>
                        <span class="block text-xs text-navy-500">{{ $hint }}</span>
                    </label>
                @endforeach
            </div>
            {{-- the item layout, apart from the colors of the design (App\Support\MenuLayouts) --}}
            <fieldset data-layout-picker>
                <legend class="field-label">{{ __('How the items are laid out') }}</legend>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach (\App\Support\MenuLayouts::CHOICES as $key)
                        @php
                            [$label, $hint] = \App\Support\MenuLayouts::label($key);
                            $layoutOn = $themes ? $s['menu_layout'] : 'auto';
                        @endphp
                        <label @class(['cursor-pointer rounded-xl border border-navy-100 p-2 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50', 'opacity-50' => !$themes && $key !== 'auto'])>
                            <input type="radio" name="menu_layout" value="{{ $key }}" class="sr-only" @checked($layoutOn === $key) @disabled(!$themes && $key !== 'auto')>
                            @include('dashboard.partials.layout-thumb', ['layout' => $key])
                            <span class="mt-1.5 block text-sm font-bold">{{ $label }}</span>
                            <span class="block text-xs text-navy-500">{{ $hint }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-navy-500">{{ __('The colors stay those of the design. Each category can have its own layout from the menu page.') }}</p>
            </fieldset>
            {{-- what a tap on an item opens: the bottom sheet (simple) or a full item page with its own link (pro) --}}
            <fieldset data-display-picker>
                <legend class="field-label">{{ __('When the guest taps an item') }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (['simple' => [__('Simple'), __('A sheet opens from the bottom of the screen, quick and light.')], 'pro' => [__('Professional'), __('A full page for the item: big photo, all details and its own link to share.')]] as $key => [$label, $hint])
                        <label @class(['flex cursor-pointer items-start gap-2 rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50', 'opacity-50' => !$themes && $key === 'pro'])>
                            <input type="radio" name="menu_display" value="{{ $key }}" class="mt-1 accent-brand-600" @checked(($themes ? $s['menu_display'] : 'simple') === $key) @disabled(!$themes && $key === 'pro')>
                            <span><span class="block font-bold">{{ $label }}</span><span class="block text-xs text-navy-500">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            {{-- the category row of the menu: chips (default) or circles with a photo / icon per category --}}
            <fieldset data-category-style-picker>
                <legend class="field-label">{{ __('The categories row on the menu') }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (['chips' => [__('Chips'), __('Rounded buttons with the category names (the usual).')], 'circles' => [__('Circles'), __('A round photo or icon for each category with its name under it.')]] as $key => [$label, $hint])
                        <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="menu_category_style" value="{{ $key }}" class="mt-1 accent-brand-600" @checked(($s['menu_category_style'] === 'circles' ? 'circles' : 'chips') === $key)>
                            <span><span class="block font-bold">{{ $label }}</span><span class="block text-xs text-navy-500">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-navy-500">{{ __('A category shows its photo in the circle, else the icon you pick for it on the menu page.') }}</p>
            </fieldset>
            {{-- badges as quick filter chips on the menu (App\Support\ItemBadges) --}}
            <fieldset data-badge-filters>
                <input type="hidden" name="badge_filters_sent" value="1">
                <legend class="field-label">{{ __('Quick filters on the menu (optional)') }}</legend>
                <p class="mb-2 text-xs text-navy-500">{{ __('Pick up to :n labels; guests tap one to see only those items (e.g. Healthy). A filter shows only while some item has its label.', ['n' => \App\Support\ItemBadges::MAX_FILTERS]) }}</p>
                @php
                    $chosenFilters = old('menu_badge_filters', \App\Support\ItemBadges::filters($s));
                @endphp
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Support\ItemBadges::forBusiness($s['business_type'])[0] as $key)
                        @php
                            $badge = \App\Support\ItemBadges::badge($key);
                        @endphp
                        <label class="flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border border-navy-100 px-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="checkbox" name="menu_badge_filters[]" value="{{ $key }}" class="sr-only" @checked(in_array($key, $chosenFilters, true))> {{ $badge['icon'] }} {{ $badge['label'] }}
                        </label>
                    @endforeach
                </div>
                @error('menu_badge_filters')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </fieldset>
            <div class="max-w-md">
                <input type="hidden" name="look_sent" value="1">
                <label class="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" name="item_placeholder" value="1" @checked($s['item_placeholder'] !== '0') class="size-4 accent-brand-600"> {{ __('Show a neat icon for items without a photo') }}</label>
                <label class="mb-3 flex items-start gap-2 text-sm"><input type="checkbox" name="menu_best_sellers" value="1" @checked($s['menu_best_sellers'] !== '0') class="mt-0.5 size-4 accent-brand-600" data-best-sellers-toggle>
                    <span>{{ __('Show "Most ordered 🔥" on top of the menu') }}<span class="block text-xs text-navy-500">{{ __('From your real orders of the last 7 days in each branch. Until there are enough orders, your recommended items show instead.') }}</span></span>
                </label>
                <label class="field-label" for="menu_intro">{{ __('Opening animation of the menu') }}</label>
                <select class="field" name="menu_intro" id="menu_intro">
                    @foreach (['logo' => __('Your logo rises over your color (light)'), 'full' => __('Logo, pattern and your tagline (full)'), 'off' => __('Off')] as $key => $label)
                        <option value="{{ $key }}" @selected($s['menu_intro'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <select class="field mt-2" name="menu_intro_repeat" aria-label="{{ __('When it plays') }}">
                    <option value="always" @selected($s['menu_intro_repeat'] !== 'session')>{{ __('Every time the menu is opened') }}</option>
                    <option value="session" @selected($s['menu_intro_repeat'] === 'session')>{{ __('Once per visit') }}</option>
                </select>
                <p class="mt-1 text-xs text-navy-500">{{ __('About 2 seconds; the guest can tap to skip it.') }}</p>
            </div>
        </section>
    </div>

    <div class="space-y-4" data-settings-panel="orders" @if ($tab !== 'orders') hidden @endif>
        <section class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-4" id="ordering">
            <h2 class="text-lg font-bold sm:col-span-2 lg:col-span-4">{{ __('Ordering') }}</h2>
            <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-2 lg:col-span-4"><input type="checkbox" name="ordering_enabled" value="1" @checked($s['ordering_enabled'] === '1') class="size-4 accent-brand-600"> {{ __('Guests can order from the table QR') }}</label>
            @php
                $tableOrdering = \App\Support\DineIn::orderingAllowed();
                $dineMode = old('dine_in_mode', \App\Support\DineIn::mode($s));
                $dineModes = [
                    'order' => ['📲', __('Send the order from the table'), __('The guest sends the order from their phone to your orders board.')],
                    'list' => ['📋', __('A list to show the waiter'), __('The guest builds a list and shows it to the waiter. Nothing is sent. Works on every plan.')],
                    'off' => ['📖', __('Menu only'), __('No cart in the hall: the guest only browses the menu.')],
                ];
            @endphp
            <div class="rounded-xl border border-navy-100 p-3 sm:col-span-2 lg:col-span-4" id="dine-in">
                <input type="hidden" name="dine_in_sent" value="1">
                <div class="mb-2 font-semibold">{{ __('In the hall (table QR and "in the place")') }}</div>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($dineModes as $key => [$icon, $label, $hint])
                        <label @class(['flex cursor-pointer gap-2 rounded-xl border border-navy-100 p-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50', 'cursor-not-allowed opacity-60' => $key === 'order' && ! $tableOrdering])>
                            <input type="radio" name="dine_in_mode" value="{{ $key }}" class="mt-1 accent-brand-600" @checked($dineMode === $key) @disabled($key === 'order' && ! $tableOrdering)>
                            <span>
                                <b class="block">{{ $icon }} {{ $label }}</b>
                                <span class="block text-xs text-navy-500">{{ $hint }}</span>
                                @if ($key === 'order' && ! $tableOrdering)
                                    <span class="mt-1 block text-xs font-semibold text-brand-700" data-dine-order-locked>{{ \App\Services\Entitlements::current()->upgradeMessage('qr_ordering') }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @if ($tableOrdering && $s['ordering_enabled'] !== '1')
                    <p class="mt-2 text-xs text-amber-700">{{ __('Ordering is switched off above, so the hall works as a list for the waiter until you switch it on.') }}</p>
                @endif
                <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" name="dine_in_show_prices" value="1" @checked(\App\Support\DineIn::showPrices($s, $dineMode)) class="size-4 accent-brand-600"> {{ __('Show prices and the total in the hall cart and list (the menu always shows prices)') }}</label>
                <label class="mt-3 block text-sm">
                    <span class="field-label">{{ __('Note on the list for the waiter') }}</span>
                    <input class="field" name="dine_in_notice" maxlength="200" value="{{ old('dine_in_notice', $s['dine_in_notice']) }}" placeholder="{{ __('This list is for showing the waiter. Hand it to the service team.') }}">
                </label>
                <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" name="order_type_prompt" value="1" @checked($s['order_type_prompt'] === '1') class="size-4 accent-brand-600"> {{ __('Ask "how will you order?" on the menu link when you offer the hall and takeaway or delivery') }}</label>
                <p class="mt-1 text-xs text-navy-500">{{ __('A table QR always means the hall. On the menu link, sending from the hall needs the table QR, so there it works as a list for the waiter.') }}</p>
            </div>
            @if (\App\Services\Entitlements::current()->allows('online_ordering'))
                <div class="rounded-xl bg-navy-50 p-3 sm:col-span-2 lg:col-span-4">
                    <div class="mb-2 font-semibold">{{ __('Online ordering from your menu link (no table)') }}</div>
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" name="online_takeaway" value="1" @checked($s['online_takeaway'] === '1') class="size-4 accent-brand-600"> {{ __('Takeaway (pickup)') }}</label>
                        <label class="flex items-center gap-2"><input type="checkbox" name="online_delivery" value="1" @checked($s['online_delivery'] === '1') class="size-4 accent-brand-600"> {{ __('Delivery (uses your delivery zones)') }}</label>
                        <label class="flex items-center gap-2">{{ __('Ready in') }} <input class="field min-h-9 w-20" name="online_prep_minutes" type="number" min="5" max="240" value="{{ $s['online_prep_minutes'] }}"> {{ __('minutes') }}</label>
                    </div>
                    <label class="mt-3 flex flex-wrap items-center gap-2 text-sm">{{ __('Cancel an online order nobody accepted after') }}
                        <input class="field min-h-9 w-20" name="online_auto_cancel_minutes" type="number" min="0" max="{{ \App\Services\Orders\AutoCancel::MAX_MINUTES }}" value="{{ $s['online_auto_cancel_minutes'] }}"> {{ __('minutes') }}
                        <span class="text-xs text-navy-500">{{ __('0 = never. Money paid from a wallet goes back to the guest; the guest sees why on the tracking page.') }}</span>
                    </label>
                </div>
            @endif
            <div><label class="field-label">{{ __('Service %') }}</label><input class="field" name="service_percent" type="number" step="0.5" min="0" max="30" value="{{ $s['service_percent'] }}"></div>
            <div><label class="field-label">{{ __('Tax %') }}</label><input class="field" name="tax_percent" type="number" step="0.5" min="0" max="30" value="{{ $s['tax_percent'] }}"></div>
        </section>
    </div>

    <div class="space-y-4" data-settings-panel="messages" @if ($tab !== 'messages') hidden @endif>
        <section class="card grid gap-4 lg:grid-cols-2" id="whatsapp">
            <div class="lg:col-span-2">
                <h2 class="section-title">{{ __('WhatsApp') }}</h2>
                <p class="muted">{{ __('Automatic WhatsApp messages to your guests. Each message uses one credit from your WhatsApp balance.') }}</p>
            </div>
            @if ($whatsapp)
                @php
                    $waToggles = [
                        'wa_order_received' => [__('Order received'), __('Online, kiosk and call-center orders')],
                        'wa_order_ready' => [__('Order ready'), __('When the order is marked ready')],
                        'wa_order_on_way' => [__('Order on the way'), __('Delivery orders leaving with the driver')],
                        'wa_reservation_confirmed' => [__('Reservation confirmed'), __('When you confirm a table booking')],
                        'wa_loyalty_reward' => [__('Loyalty reward earned'), __('When a guest\'s card reaches a reward')],
                        'wa_order_to_staff' => [__('New orders to your own WhatsApp'), __('Table, online and kiosk orders go to each branch\'s WhatsApp number (or the restaurant\'s) as well as the order board')],
                    ];
                @endphp
                <div class="space-y-2">
                    @foreach ($waToggles as $key => [$label, $hint])
                        <label class="flex items-start gap-3 rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="checkbox" name="{{ $key }}" value="1" @checked($s[$key] === '1') class="mt-0.5 size-4 accent-brand-600">
                            <span><span class="block text-sm font-semibold">{{ $label }}</span><span class="block text-xs text-navy-500">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="field-label" for="wa_sender_name">{{ __('Sender name') }}</label>
                        <input class="field" id="wa_sender_name" name="wa_sender_name" value="{{ old('wa_sender_name', $s['wa_sender_name']) }}" maxlength="40"
                            placeholder="{{ $s['brand_name'] ?: tenant()->name }}" data-wa-sender-input>
                        <p class="mt-1 text-xs text-navy-500">{{ __('How your place signs its messages. Empty = your restaurant name.') }}</p>
                    </div>
                    <div>
                        <div class="field-label">{{ __('Preview') }}</div>
                        @php
                            $waLocale = \App\Services\WhatsApp\WhatsApp::locale();
                            $waSample = \App\Services\WhatsApp\Templates::render('order_ready', \App\Services\WhatsApp\Templates::params('order_ready', [
                                'name' => $waLocale === 'en' ? 'Ahmed' : 'أحمد', 'restaurant' => '{restaurant}', 'number' => '128',
                                'link' => tenant()->url('o/…'),
                            ]), $waLocale);
                            $waSender = \App\Services\Notify\GuestNotifier::senderName();
                        @endphp
                        <div class="rounded-2xl bg-[#e5ddd5] p-3" dir="{{ $waLocale === 'en' ? 'ltr' : 'rtl' }}">
                            <div class="max-w-sm rounded-xl rounded-ss-none bg-white p-3 text-sm leading-relaxed shadow-sm">
                                {!! str_replace(e('{restaurant}'), '<b data-wa-sender>'.e($waSender).'</b>', e($waSample)) !!}
                                <div class="mt-1 text-end text-[11px] text-navy-400" dir="ltr">12:30 ✓✓</div>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-navy-500">{{ __('Messages are in your menu\'s default language.') }}
                            @can('billing.manage')<a href="{{ route('dash.billing') }}#whatsapp-credits" class="text-brand-700 underline">{{ __('WhatsApp credits') }}</a>@endcan
                        </p>
                    </div>
                </div>
                <script>
                    document.querySelector('[data-wa-sender-input]')?.addEventListener('input', (e) => {
                        const el = document.querySelector('[data-wa-sender]');
                        if (el) el.textContent = e.target.value.trim() || e.target.placeholder;
                    });
                </script>
            @else
                <div class="rounded-xl bg-navy-50 p-3 text-sm lg:col-span-2">
                    {{ \App\Services\Entitlements::current()->upgradeMessage('whatsapp_notifications') }}
                    @can('billing.manage')<a href="{{ route('dash.billing') }}" class="font-semibold text-brand-700 underline">{{ __('Add-ons') }}</a>@endcan
                </div>
            @endif
        </section>

        @if ($advancedReports)
            <section class="card" id="weekly-report">
                <h2 class="text-lg font-bold">{{ __('Weekly report') }}</h2>
                <label class="mt-2 flex items-center gap-2 text-sm font-semibold"><input type="hidden" name="weekly_report_email" value="0"><input type="checkbox" name="weekly_report_email" value="1" @checked($s['weekly_report_email'] === '1') class="size-4 accent-brand-600"> {{ __('Email the owners last week\'s numbers every Monday at 8 AM') }}</label>
            </section>
        @endif
    </div>

    <div class="space-y-4" data-settings-panel="languages" @if ($tab !== 'languages') hidden @endif>
        <section class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <h2 class="text-lg font-bold sm:col-span-2 lg:col-span-4">{{ __('Language & currency') }}</h2>
            <div>
                <label class="field-label">{{ __('Menu opens in') }}</label>
                <select class="field" name="default_locale">
                    <option value="ar" @selected($s['default_locale'] === 'ar')>العربية</option>
                    <option value="en" @selected($s['default_locale'] === 'en')>English</option>
                </select>
            </div>
            <div><label class="field-label">{{ __('Currency (Arabic)') }}</label><input class="field" name="currency" value="{{ $s['currency'] }}" required maxlength="10"></div>
            <div><label class="field-label">{{ __('Currency (English)') }}</label><input class="field" name="currency_en" value="{{ $s['currency_en'] }}" required maxlength="10" dir="ltr"></div>
            <label class="flex items-center gap-2 self-end text-sm font-semibold"><input type="checkbox" name="english_enabled" value="1" @checked($s['english_enabled'] === '1') class="size-4 accent-brand-600"> {{ __('Show English menu') }}</label>
        </section>

        @if ($extraLanguages)
            <section class="card space-y-3" id="languages">
                <div>
                    <h2 class="text-lg font-bold">{{ __('Extra menu languages') }}</h2>
                    <p class="text-sm text-navy-500">{{ __('Guests pick the language from the menu. Items without a translation show in English, then Arabic.') }}</p>
                </div>
                @php
                    $chosenLocales = old('menu_locales', \App\Support\MenuLocales::parse($s['menu_locales']));
                @endphp
                <input type="hidden" name="menu_locales_sent" value="1">
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Support\MenuLocales::ALL as $code => [$native])
                        <label class="flex min-h-10 cursor-pointer items-center gap-2 rounded-xl border border-navy-100 px-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="checkbox" name="menu_locales[]" value="{{ $code }}" class="accent-brand-600" @checked(in_array($code, (array) $chosenLocales, true))>
                            <span lang="{{ $code }}">{{ $native }}</span> <span class="text-navy-500">· {{ \App\Support\MenuLocales::label($code) }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-navy-500">{{ __('Write the translations on each item, or fill them all at once with AI from the menu page.') }}</p>
            </section>
        @endif
    </div>

    <div class="space-y-4" data-settings-panel="account" @if ($tab !== 'account') hidden @endif>
        @if (auth()->user()->isOwner())
            <section class="card" id="security">
                <h2 class="text-lg font-bold">{{ __('Security') }}</h2>
                <label class="mt-2 flex items-center gap-2 text-sm font-semibold"><input type="hidden" name="require_2fa" value="0"><input type="checkbox" name="require_2fa" value="1" @checked($s['require_2fa'] === '1') class="size-4 accent-brand-600"> {{ __('Require two-factor authentication from every staff member') }}</label>
                <p class="mt-1 text-sm text-navy-500">{{ __('Staff without it are taken to the setup page when they sign in.') }} <a href="{{ route('dash.security') }}" class="text-brand-700 underline">{{ __('Your own account security') }}</a></p>
            </section>
        @endif
        @unless (auth()->user()->isOwner())
            <section class="card" id="account-security">
                <h2 class="text-lg font-bold">{{ __('Security') }}</h2>
                <p class="mt-1 text-sm text-navy-500">{{ __('Two-factor sign-in for your own account.') }} <a href="{{ route('dash.security') }}" class="text-brand-700 underline">{{ __('Your own account security') }}</a></p>
            </section>
        @endunless

        <section class="card grid gap-4 sm:grid-cols-3" id="tracking">
            <div class="sm:col-span-3">
                <h2 class="text-lg font-bold">{{ __('Ad tracking') }}</h2>
                <p class="text-sm text-navy-500">{{ __('Measure your ads: your pixels load on your public menu pages only and send the standard events (page view, item viewed, added to cart, order placed with its value).') }}</p>
            </div>
            @if ($pixels)
                <input type="hidden" name="pixels_sent" value="1">
                <div><label class="field-label" for="meta_pixel_id">Meta Pixel ID</label><input class="field" id="meta_pixel_id" name="meta_pixel_id" value="{{ old('meta_pixel_id', $s['meta_pixel_id']) }}" dir="ltr" inputmode="numeric" placeholder="123456789012345">@error('meta_pixel_id')<div class="field-error">{{ $message }}</div>@enderror</div>
                <div><label class="field-label" for="ga4_measurement_id">Google Analytics 4</label><input class="field" id="ga4_measurement_id" name="ga4_measurement_id" value="{{ old('ga4_measurement_id', $s['ga4_measurement_id']) }}" dir="ltr" placeholder="G-XXXXXXXXXX">@error('ga4_measurement_id')<div class="field-error">{{ $message }}</div>@enderror</div>
                <div><label class="field-label" for="tiktok_pixel_id">TikTok Pixel ID</label><input class="field" id="tiktok_pixel_id" name="tiktok_pixel_id" value="{{ old('tiktok_pixel_id', $s['tiktok_pixel_id']) }}" dir="ltr" placeholder="CXXXXXXXXXXXXXXXXXXX">@error('tiktok_pixel_id')<div class="field-error">{{ $message }}</div>@enderror</div>
                <p class="rounded-xl bg-navy-50 p-3 text-xs text-navy-600 sm:col-span-3">{{ __('Privacy: the pixels belong to your own ad accounts and only see what guests do on your menu pages, never their names or phone numbers. You are responsible for telling your guests about them, as described in the') }}
                    <a href="{{ route('privacy') }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 underline">{{ __('Privacy policy') }}</a>.</p>
            @else
                <div class="rounded-xl bg-navy-50 p-3 text-sm sm:col-span-3">
                    {{ \App\Services\Entitlements::current()->upgradeMessage('full_branding') }}
                    @can('billing.manage')<a href="{{ route('dash.billing') }}" class="font-semibold text-brand-700 underline">{{ __('Upgrade') }}</a>@endcan
                </div>
            @endif
        </section>

        @if ($ai)
            <section class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-3" id="ai">
                <div class="sm:col-span-2 lg:col-span-3">
                    <h2 class="section-title">{{ __('AI menu import (your own key)') }}</h2>
                    <p class="muted">{{ __('Reading a menu photo costs a few piasters on your own account. The key is stored encrypted and never shown again.') }}</p>
                </div>
                <div>
                    <label class="field-label">{{ __('Provider') }}</label>
                    <select class="field" name="ai_provider" data-ai-provider>
                        <option value="">—</option>
                        @foreach ($providers as $key => [$label, $model, $url])
                            <option value="{{ $key }}" data-model="{{ $model }}" data-url="{{ $url }}" @selected($s['ai_provider'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <a href="#" target="_blank" rel="noopener" class="mt-1 hidden text-xs text-brand-700 underline" data-ai-link>{{ __('Get an API key') }}</a>
                </div>
                <div>
                    <label class="field-label">{{ __('Model (optional)') }}</label>
                    <input class="field" name="ai_model" value="{{ $s['ai_model'] }}" dir="ltr" data-ai-model maxlength="80">
                    <p class="mt-1 text-xs text-navy-500">{{ __('Leave empty for the cheapest recommended model.') }}</p>
                </div>
                <div>
                    <label class="field-label">{{ __('API key') }}</label>
                    <input class="field" name="ai_key" type="password" autocomplete="off" dir="ltr" placeholder="{{ $aiKey ?: '' }}">
                    @if ($aiKey)<label class="mt-1 flex items-center gap-2 text-xs"><input type="checkbox" name="remove_ai_key" value="1" class="size-4 accent-brand-600"> {{ __('Remove the saved key') }}</label>@endif
                    @error('ai_key')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                @if ($aiKey)
                    <div class="sm:col-span-2 lg:col-span-3"><button type="submit" form="ai-test-form" class="btn-ghost" data-ai-test>🔌 {{ __('Test the AI key') }}</button></div>
                @endif
            </section>
        @endif
    </div>

    <div class="dash-savebar" data-settings-save>
        <div class="flex flex-wrap items-center justify-end gap-2">
            <span class="me-auto text-xs font-semibold text-amber-700" data-unsaved-note hidden>● {{ __('Changes not saved yet') }}</span>
            <button class="btn-primary min-w-40" data-primary-save>{{ __('Save settings') }}</button>
        </div>
    </div>
</form>

@if ($ai && $aiKey)
    <form method="post" action="{{ route('dash.settings.ai-test') }}" id="ai-test-form" hidden>@csrf</form>
@endif

<script>
    // tabs: one form, one save; a tab only shows its part. ?tab= and #section links open the right tab, and after a
    // failed save the first tab with an error opens.
    (() => {
        const panels = [...document.querySelectorAll('[data-settings-panel]')];
        const tabs = [...document.querySelectorAll('[data-settings-tab]')];
        const input = document.querySelector('[data-settings-tab-input]');
        const show = (key, push = true) => {
            if (!panels.some((p) => p.dataset.settingsPanel === key)) return;
            panels.forEach((p) => { p.hidden = p.dataset.settingsPanel !== key; });
            tabs.forEach((t) => {
                const on = t.dataset.settingsTab === key;
                t.classList.toggle('bg-navy-900', on);
                t.classList.toggle('text-white', on);
                t.classList.toggle('bg-white', !on);
                t.classList.toggle('text-navy-700', !on);
                t.classList.toggle('ring-1', !on);
                on ? t.setAttribute('aria-current', 'page') : t.removeAttribute('aria-current');
            });
            input.value = key;
            if (push) {
                const url = new URL(location.href);
                url.searchParams.set('tab', key);
                url.hash = '';
                history.replaceState(null, '', url);
            }
        };
        tabs.forEach((t) => t.addEventListener('click', (e) => {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
            e.preventDefault();
            show(t.dataset.settingsTab);
        }));
        const panelOf = (el) => el?.closest('[data-settings-panel]')?.dataset.settingsPanel;
        const error = document.querySelector('[data-settings-panel] .field-error, [data-settings-panel] .text-red-600');
        const target = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
        if (error) {
            show(panelOf(error), false);
            error.scrollIntoView({ block: 'center' });
        } else if (panelOf(target)) {
            show(panelOf(target), false);
            target.scrollIntoView();
        }
        window.addEventListener('hashchange', () => {
            const el = document.getElementById(decodeURIComponent(location.hash.slice(1)));
            if (panelOf(el)) { show(panelOf(el), false); el.scrollIntoView(); }
        });
    })();

    (() => {
        const sel = document.querySelector('[data-ai-provider]');
        if (!sel) return;
        const sync = () => {
            const o = sel.selectedOptions[0];
            document.querySelector('[data-ai-model]').placeholder = o?.dataset.model || '';
            const a = document.querySelector('[data-ai-link]');
            a.classList.toggle('hidden', !o?.dataset.url);
            a.href = o?.dataset.url || '#';
        };
        sel.addEventListener('change', sync);
        sync();
    })();
</script>
@endsection
