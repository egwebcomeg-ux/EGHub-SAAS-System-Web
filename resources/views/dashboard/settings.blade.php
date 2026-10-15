@extends('layouts.dashboard')
@section('title', __('Settings'))

@section('content')
<h1 class="mb-4 text-2xl font-extrabold">{{ __('Settings') }}</h1>

<form method="post" action="{{ route('dash.settings.update') }}" enctype="multipart/form-data" class="space-y-4">
    @csrf
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
    </section>

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

    <section class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <h2 class="text-lg font-bold sm:col-span-2 lg:col-span-4">{{ __('Ordering') }}</h2>
        <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-2 lg:col-span-4"><input type="checkbox" name="ordering_enabled" value="1" @checked($s['ordering_enabled'] === '1') class="size-4 accent-brand-600"> {{ __('Guests can order from the table QR') }}</label>
        @if (\App\Services\Entitlements::current()->allows('online_ordering'))
            <div class="rounded-xl bg-navy-50 p-3 sm:col-span-2 lg:col-span-4">
                <div class="mb-2 font-semibold">{{ __('Online ordering from your menu link (no table)') }}</div>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" name="online_takeaway" value="1" @checked($s['online_takeaway'] === '1') class="size-4 accent-brand-600"> {{ __('Takeaway (pickup)') }}</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="online_delivery" value="1" @checked($s['online_delivery'] === '1') class="size-4 accent-brand-600"> {{ __('Delivery (uses your delivery zones)') }}</label>
                    <label class="flex items-center gap-2">{{ __('Ready in') }} <input class="field min-h-9 w-20" name="online_prep_minutes" type="number" min="5" max="240" value="{{ $s['online_prep_minutes'] }}"> {{ __('minutes') }}</label>
                </div>
            </div>
        @endif
        <div><label class="field-label">{{ __('Service %') }}</label><input class="field" name="service_percent" type="number" step="0.5" min="0" max="30" value="{{ $s['service_percent'] }}"></div>
        <div><label class="field-label">{{ __('Tax %') }}</label><input class="field" name="tax_percent" type="number" step="0.5" min="0" max="30" value="{{ $s['tax_percent'] }}"></div>
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
        </section>
    @endif

    <button class="btn-primary">{{ __('Save settings') }}</button>
</form>

@if ($ai && $aiKey)
    <form method="post" action="{{ route('dash.settings.ai-test') }}" class="mt-3">@csrf<button class="btn-ghost">🔌 {{ __('Test the AI key') }}</button></form>
@endif

<script>
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
