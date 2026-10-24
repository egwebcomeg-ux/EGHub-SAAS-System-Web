@extends('layouts.landing')
@section('title', __('Free QR code generator with style and logo | EgHub'))
@section('description', __('Make a free QR code for a link, WhatsApp, Wi-Fi, a contact card or a location. Pick the shapes and colours, add your logo and a "Scan me" frame, and download PNG, SVG or a print-ready PDF. No sign-up.'))

@php
    $types = [
        'url' => __('Link'),
        'text' => __('Text'),
        'whatsapp' => __('WhatsApp'),
        'phone' => __('Phone'),
        'email' => __('Email'),
        'wifi' => __('Wi-Fi'),
        'vcard' => __('Contact card'),
        'location' => __('Location'),
        'sms' => __('SMS'),
    ];
    $bodyShapes = ['square' => __('Square'), 'rounded' => __('Rounded'), 'dots' => __('Dots'), 'liquid' => __('Liquid'), 'classy' => __('Classy'), 'diamond' => __('Diamond'), 'vlines' => __('Vertical lines'), 'hlines' => __('Horizontal lines')];
    $frameShapes = ['square' => __('Square'), 'rounded' => __('Rounded'), 'circle' => __('Circle'), 'leaf' => __('Leaf'), 'double' => __('Two corners')];
    $ballShapes = ['square' => __('Square'), 'rounded' => __('Rounded'), 'circle' => __('Circle'), 'leaf' => __('Leaf'), 'double' => __('Two corners'), 'diamond' => __('Diamond')];
    $icons = ['menu' => __('Menu'), 'whatsapp' => __('WhatsApp'), 'instagram' => __('Instagram'), 'facebook' => __('Facebook'), 'location' => __('Location'), 'wifi' => __('Wi-Fi')];
    $frames = ['none' => __('No frame'), 'bottom' => __('Label below'), 'top' => __('Label above'), 'pill' => __('Speech bubble')];

    // one-click designs; every design field is set so a preset never inherits a leftover choice
    $base = [
        'body' => 'square', 'eye_frame' => 'square', 'eye_ball' => 'square', 'fill_type' => 'solid', 'color1' => '#0a2540', 'color2' => '#0a2540', 'angle' => 45,
        'eye_custom' => false, 'eye_frame_color' => '#0a2540', 'eye_ball_color' => '#0a2540', 'bg_color' => '#ffffff', 'bg_transparent' => false,
        'logo' => 'none', 'logo_size' => 22, 'logo_clear' => true, 'logo_plate' => true,
        'frame' => 'none', 'frame_text' => __('Scan me'), 'frame_color' => '#0a2540', 'frame_text_color' => '#ffffff',
    ];
    $presets = [
        'egmenu' => [__('EgHub orange'), ['body' => 'liquid', 'eye_frame' => 'rounded', 'eye_ball' => 'rounded', 'fill_type' => 'linear', 'color1' => '#e05310', 'color2' => '#a93a0b', 'eye_custom' => true, 'eye_frame_color' => '#0a2540', 'eye_ball_color' => '#e05310'] + $base],
        'navy' => [__('Navy classic'), $base],
        'sunset' => [__('Gradient sunset'), ['body' => 'dots', 'eye_frame' => 'leaf', 'eye_ball' => 'leaf', 'fill_type' => 'linear', 'color1' => '#b3125a', 'color2' => '#e04e00', 'angle' => 35] + $base],
        'minimal' => [__('Dots minimal'), ['body' => 'dots', 'eye_frame' => 'circle', 'eye_ball' => 'circle', 'color1' => '#111827', 'color2' => '#111827'] + $base],
        'gold' => [__('Luxury gold'), ['body' => 'classy', 'eye_frame' => 'double', 'eye_ball' => 'diamond', 'fill_type' => 'radial', 'color1' => '#5c430b', 'color2' => '#9c7418', 'eye_custom' => true, 'eye_frame_color' => '#1a1a1a', 'eye_ball_color' => '#9c7418', 'bg_color' => '#fffdf5', 'frame' => 'bottom', 'frame_color' => '#111111', 'frame_text_color' => '#d9b54a'] + $base],
        'restaurant' => [__('Restaurant menu'), ['body' => 'rounded', 'eye_frame' => 'rounded', 'eye_ball' => 'rounded', 'eye_custom' => true, 'eye_frame_color' => '#e05310', 'eye_ball_color' => '#0a2540', 'logo' => 'menu', 'logo_size' => 24, 'frame' => 'bottom', 'frame_text' => __('Scan the menu'), 'frame_color' => '#e05310'] + $base],
    ];
    $d = $presets['egmenu'][1];

    $i18n = [
        'empty' => __('Fill in the content and your code appears here.'),
        'tooLong' => __('This content is too long for one QR code. Shorten it or lower the error correction.'),
        'loading' => __('Drawing your code…'),
        'failed' => __('Could not draw the code. Check your connection and try again.'),
        'slowDown' => __('Too many changes in a minute. Wait a moment and the code updates.'),
        'lowContrast' => __('The colours are too close to the background: cameras may not read it. Use a darker colour on a light background.'),
        'inverted' => __('A light code on a dark background: many cameras cannot read it. Keep the code darker than the background.'),
        'bigLogo' => __('The logo is big: keep it under 30% of the code, or turn on the plate behind it, and test the scan.'),
        'transparent' => __('Transparent background: print it on a light surface only.'),
    ];
    $faq = [
        [__('Is the QR code really free?'), __('Yes. Make as many codes as you like, without an account and without a watermark. The code holds your content directly, so it keeps working forever.')],
        [__('Is my logo uploaded anywhere?'), __('No. The logo stays in your browser: the page draws the code on your device and never sends the logo to our server.')],
        [__('Will a styled code still scan?'), __('Yes, as long as the code stays darker than the background and the logo stays small. The page warns you when a choice makes it hard to read; scan it with your phone camera before you print.')],
        [__('Which file should I download?'), __('PNG for social media and WhatsApp, SVG for designers and large prints, and Print / PDF for an A4 page ready to print.')],
        [__('Can I change the link after printing?'), __('Not with a free static code: the link is inside the code. With an EgHub menu, your QR opens your menu and you update the menu any time without reprinting.')],
    ];
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => __('EgHub QR code generator'),
        'url' => route('tools.qr'),
        'applicationCategory' => 'DesignApplication',
        'operatingSystem' => 'Web',
        'browserRequirements' => 'Requires JavaScript',
        'isAccessibleForFree' => true,
        'inLanguage' => ['ar', 'en'],
        'description' => __('Make a free QR code for a link, WhatsApp, Wi-Fi, a contact card or a location. Pick the shapes and colours, add your logo and a "Scan me" frame, and download PNG, SVG or a print-ready PDF. No sign-up.'),
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EGP'],
        'publisher' => ['@id' => platform_url(null, '/').'#organization'],
    ];
@endphp

@push('head')
    @vite('resources/js/qr-tool.js')
    <script type="application/ld+json">{!! \App\Services\Seo\RestaurantProfile::json($schema) !!}</script>
@endpush

@section('page')
<section data-hero class="lp-hero lp-glow">
    <div class="mx-auto max-w-3xl px-4 pt-8 pb-10 text-center sm:px-6 lg:pt-12 lg:pb-12">
        <p class="lp-eyebrow" data-reveal>{{ __('Free tool') }}</p>
        <h1 class="mx-auto mt-4 text-[1.9rem] leading-[1.3] font-black tracking-tight sm:text-5xl sm:leading-[1.2]" data-reveal style="--d: 70ms">{{ __('Free QR code generator, with your style and logo') }}</h1>
        <p class="lp-lead mx-auto mt-4 max-w-2xl" data-reveal style="--d: 140ms">{{ __('A link, WhatsApp, Wi-Fi, a contact card or a location: pick the shapes and colours, add your logo and download in high quality.') }}</p>
        <ul class="mt-6 flex flex-wrap justify-center gap-2 text-sm font-bold text-navy-700" data-reveal style="--d: 210ms">
            @foreach ([__('No sign-up'), __('No watermark'), __('Never expires'), __('Your logo stays on your device')] as $perk)
                <li class="inline-flex min-h-9 items-center gap-1.5 rounded-full border border-navy-100 bg-white/80 px-3"><x-landing.icon name="check" class="size-4 text-emerald-600" />{{ $perk }}</li>
            @endforeach
        </ul>
    </div>
</section>

<section class="py-8 lg:py-12" aria-label="{{ __('QR code generator') }}">
    <div class="qr-tool mx-auto max-w-7xl px-3 sm:px-6" data-qr-tool
         data-endpoint="{{ route('tools.qr.matrix') }}" data-max="{{ $maxLength }}"
         data-qr-i18n="{{ json_encode($i18n, JSON_UNESCAPED_UNICODE) }}"
         data-presets="{{ json_encode(collect($presets)->map(fn ($p) => $p[1]), JSON_UNESCAPED_UNICODE) }}"
         data-credit="{{ __('Made free on eghub.app') }}">
        <form data-qr-form class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-8" novalidate>
            <input type="hidden" name="type" value="url">

            {{-- 1. content --}}
            <div class="overflow-hidden rounded-3xl border border-navy-100 bg-white shadow-[0_1px_2px_rgba(20,31,56,.04),0_18px_40px_-28px_rgba(20,31,56,.35)] lg:col-start-1 lg:row-start-1">
                <div role="tablist" aria-label="{{ __('What should the code open?') }}" class="qr-tabs flex gap-1 overflow-x-auto border-b border-navy-100 bg-navy-50/60 p-2">
                    @foreach ($types as $type => $label)
                        <button type="button" role="tab" id="qr-tab-{{ $type }}" aria-controls="qr-panel-{{ $type }}" data-qr-tab="{{ $type }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? 0 : -1 }}"
                                class="min-h-11 shrink-0 rounded-xl px-3.5 text-sm font-extrabold whitespace-nowrap text-navy-600 transition hover:bg-white hover:text-navy-900 aria-selected:bg-navy-800 aria-selected:text-white aria-selected:shadow-sm">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="p-4 sm:p-6">
                    <div role="tabpanel" id="qr-panel-url" aria-labelledby="qr-tab-url" data-qr-panel="url">
                        <label class="block"><span class="field-label">{{ __('Your link') }}</span>
                            <input name="url_url" type="url" inputmode="url" dir="ltr" class="field text-start" value="https://egmenu.app" placeholder="https://" maxlength="{{ $maxLength }}" autocomplete="url"></label>
                    </div>
                    <div role="tabpanel" id="qr-panel-text" aria-labelledby="qr-tab-text" data-qr-panel="text" hidden>
                        <label class="block"><span class="field-label">{{ __('Your text') }}</span>
                            <textarea name="text_text" rows="4" class="field py-2.5" maxlength="{{ $maxLength }}" placeholder="{{ __('Any text, up to :max characters', ['max' => $maxLength]) }}"></textarea></label>
                    </div>
                    <div role="tabpanel" id="qr-panel-whatsapp" aria-labelledby="qr-tab-whatsapp" data-qr-panel="whatsapp" hidden class="grid gap-4">
                        <label class="block"><span class="field-label">{{ __('WhatsApp number') }}</span>
                            <input name="wa_phone" type="tel" inputmode="tel" dir="ltr" class="field text-start" placeholder="01xxxxxxxxx" autocomplete="tel"></label>
                        <label class="block"><span class="field-label">{{ __('Ready message (optional)') }}</span>
                            <textarea name="wa_message" rows="3" class="field py-2.5" maxlength="500" placeholder="{{ __('Hello, I want to order…') }}"></textarea></label>
                        <p class="muted">{{ __('An Egyptian number starting with 01 gets the country code by itself.') }}</p>
                    </div>
                    <div role="tabpanel" id="qr-panel-phone" aria-labelledby="qr-tab-phone" data-qr-panel="phone" hidden>
                        <label class="block"><span class="field-label">{{ __('Phone number') }}</span>
                            <input name="tel_phone" type="tel" inputmode="tel" dir="ltr" class="field text-start" placeholder="+20 1xx xxx xxxx" autocomplete="tel"></label>
                    </div>
                    <div role="tabpanel" id="qr-panel-email" aria-labelledby="qr-tab-email" data-qr-panel="email" hidden class="grid gap-4 sm:grid-cols-2">
                        <label class="block"><span class="field-label">{{ __('Email address') }}</span>
                            <input name="email_to" type="email" dir="ltr" class="field text-start" placeholder="name@example.com" autocomplete="email"></label>
                        <label class="block"><span class="field-label">{{ __('Subject (optional)') }}</span>
                            <input name="email_subject" class="field" maxlength="150"></label>
                        <label class="block sm:col-span-2"><span class="field-label">{{ __('Message (optional)') }}</span>
                            <textarea name="email_body" rows="3" class="field py-2.5" maxlength="1000"></textarea></label>
                    </div>
                    <div role="tabpanel" id="qr-panel-wifi" aria-labelledby="qr-tab-wifi" data-qr-panel="wifi" hidden class="grid gap-4 sm:grid-cols-2">
                        <label class="block"><span class="field-label">{{ __('Network name (SSID)') }}</span>
                            <input name="wifi_ssid" dir="auto" class="field" maxlength="64" autocomplete="off"></label>
                        <label class="block" data-qr-when="wifi_enc=WPA|WEP"><span class="field-label">{{ __('Password') }}</span>
                            <input name="wifi_password" dir="ltr" class="field text-start" maxlength="64" autocomplete="off"></label>
                        <label class="block"><span class="field-label">{{ __('Security') }}</span>
                            <select name="wifi_enc" class="field"><option value="WPA">WPA / WPA2 / WPA3</option><option value="WEP">WEP</option><option value="nopass">{{ __('No password') }}</option></select></label>
                        <label class="flex min-h-11 items-center gap-2 self-end text-sm font-semibold text-navy-800"><input type="checkbox" name="wifi_hidden" class="size-5 accent-brand-700">{{ __('Hidden network') }}</label>
                    </div>
                    <div role="tabpanel" id="qr-panel-vcard" aria-labelledby="qr-tab-vcard" data-qr-panel="vcard" hidden class="grid gap-4 sm:grid-cols-2">
                        <label class="block"><span class="field-label">{{ __('Name') }}</span><input name="vc_name" class="field" maxlength="80" autocomplete="name"></label>
                        <label class="block"><span class="field-label">{{ __('Phone') }}</span><input name="vc_phone" type="tel" dir="ltr" class="field text-start" maxlength="30" autocomplete="tel"></label>
                        <label class="block"><span class="field-label">{{ __('Email') }}</span><input name="vc_email" type="email" dir="ltr" class="field text-start" maxlength="120" autocomplete="email"></label>
                        <label class="block"><span class="field-label">{{ __('Company') }}</span><input name="vc_company" class="field" maxlength="80" autocomplete="organization"></label>
                        <label class="block sm:col-span-2"><span class="field-label">{{ __('Website') }}</span><input name="vc_website" type="url" dir="ltr" class="field text-start" maxlength="200" placeholder="https://" autocomplete="url"></label>
                    </div>
                    <div role="tabpanel" id="qr-panel-location" aria-labelledby="qr-tab-location" data-qr-panel="location" hidden class="grid gap-4 sm:grid-cols-2">
                        <label class="block"><span class="field-label">{{ __('Latitude') }}</span><input name="geo_lat" inputmode="decimal" dir="ltr" class="field text-start" placeholder="30.0444"></label>
                        <label class="block"><span class="field-label">{{ __('Longitude') }}</span><input name="geo_lng" inputmode="decimal" dir="ltr" class="field text-start" placeholder="31.2357"></label>
                        <fieldset class="sm:col-span-2">
                            <legend class="field-label">{{ __('Opens as') }}</legend>
                            <div class="flex flex-wrap gap-2">
                                @foreach (['maps' => __('Google Maps link'), 'geo' => __('Map app (geo:)')] as $value => $label)
                                    <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-navy-200 px-3 text-sm font-bold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"><input type="radio" name="geo_format" value="{{ $value }}" @checked($loop->first) class="accent-brand-700">{{ $label }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        <p class="muted sm:col-span-2">{{ __('In Google Maps, press and hold the place: the two numbers appear at the top.') }}</p>
                    </div>
                    <div role="tabpanel" id="qr-panel-sms" aria-labelledby="qr-tab-sms" data-qr-panel="sms" hidden class="grid gap-4">
                        <label class="block"><span class="field-label">{{ __('Phone number') }}</span><input name="sms_phone" type="tel" dir="ltr" class="field text-start" placeholder="+201xxxxxxxxx"></label>
                        <label class="block"><span class="field-label">{{ __('Message (optional)') }}</span><textarea name="sms_message" rows="3" class="field py-2.5" maxlength="500"></textarea></label>
                    </div>
                </div>
            </div>

            {{-- 2. preview and downloads (sticky on desktop) --}}
            <div data-qr-preview-card class="lg:sticky lg:top-24 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:self-start">
                <div class="rounded-3xl border border-navy-100 bg-white p-4 shadow-[0_1px_2px_rgba(20,31,56,.04),0_24px_50px_-30px_rgba(20,31,56,.45)] sm:p-5">
                    <div class="qr-stage overflow-hidden rounded-2xl border border-navy-100 p-4">
                        <div data-qr-preview role="img" aria-label="{{ __('Preview of your QR code') }}" class="mx-auto max-w-[340px]"></div>
                    </div>
                    <p data-qr-status aria-live="polite" class="mt-2 min-h-5 text-center text-xs font-semibold text-navy-500"></p>
                    <ul data-qr-warnings role="status" hidden class="mt-2 space-y-1.5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-relaxed font-semibold text-amber-900 [&>li]:ps-1"></ul>

                    <label class="mt-3 block">
                        <span class="flex items-center justify-between text-sm font-bold text-navy-800">{{ __('PNG size') }} <output data-qr-size-out dir="ltr" class="text-xs font-semibold text-navy-500">1024 × 1024 px</output></span>
                        <input type="range" name="png_size" min="512" max="2048" step="128" value="1024" aria-describedby="qr-size-help">
                        <span id="qr-size-help" class="flex justify-between text-xs text-navy-500"><span>{{ __('Lighter') }}</span><span>{{ __('Sharper') }}</span></span>
                    </label>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" data-qr-download="png" disabled class="btn-primary col-span-2 min-h-12 rounded-full text-base">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>{{ __('Download PNG') }}
                        </button>
                        <button type="button" data-qr-download="svg" disabled class="btn-ghost min-h-11 rounded-full">{{ __('SVG (vector)') }}</button>
                        <button type="button" data-qr-download="pdf" disabled class="btn-ghost min-h-11 rounded-full">{{ __('Print / PDF') }}</button>
                    </div>
                    <p class="mt-4 flex gap-2 rounded-2xl bg-navy-50 px-3 py-2.5 text-sm leading-relaxed font-semibold text-navy-700">
                        <x-landing.icon name="phone" class="mt-0.5 size-5 shrink-0 text-brand-700" />{{ __('Try scanning it with your phone camera before you print it.') }}
                    </p>
                </div>
            </div>

            {{-- 3. design --}}
            <div class="space-y-3 lg:col-start-1 lg:row-start-2">
                <div class="rounded-3xl border border-navy-100 bg-white p-4 sm:p-6">
                    <h2 class="text-base font-black text-navy-900">{{ __('Ready designs') }}</h2>
                    <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-6">
                        @foreach ($presets as $key => [$label])
                            <button type="button" data-qr-preset="{{ $key }}" aria-pressed="false" class="group flex flex-col items-center gap-1.5 rounded-2xl border border-navy-100 p-2 text-center text-xs font-bold text-navy-700 transition hover:border-navy-300 aria-pressed:border-brand-600 aria-pressed:bg-brand-50">
                                <span data-qr-preset-thumb class="qr-thumb block w-full max-w-24 rounded-lg bg-navy-50"></span>
                                <span class="leading-tight">{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- colours --}}
                <details open class="rounded-3xl border border-navy-100 bg-white">
                    <summary class="flex min-h-14 cursor-pointer items-center justify-between gap-3 px-4 text-base font-black text-navy-900 sm:px-6">{{ __('Colours') }}<svg class="qr-chev size-5 text-navy-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg></summary>
                    <div class="grid gap-5 border-t border-navy-100 px-4 py-5 sm:px-6">
                        <fieldset>
                            <legend class="field-label">{{ __('Code colour') }}</legend>
                            <div class="flex flex-wrap gap-2">
                                @foreach (['solid' => __('One colour'), 'linear' => __('Linear gradient'), 'radial' => __('Radial gradient')] as $value => $label)
                                    <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-navy-200 px-3 text-sm font-bold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"><input type="radio" name="fill_type" value="{{ $value }}" @checked($d['fill_type'] === $value) class="accent-brand-700">{{ $label }}</label>
                                @endforeach
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-4">
                                <label class="flex items-center gap-2 text-sm font-semibold text-navy-700"><input type="color" name="color1" value="{{ $d['color1'] }}"><span data-qr-when="fill_type=linear|radial">{{ __('Start colour') }}</span><span data-qr-when="fill_type=solid">{{ __('Colour') }}</span></label>
                                <label class="flex items-center gap-2 text-sm font-semibold text-navy-700" data-qr-when="fill_type=linear|radial"><input type="color" name="color2" value="{{ $d['color2'] }}">{{ __('End colour') }}</label>
                                <label class="min-w-40 flex-1 text-sm font-semibold text-navy-700" data-qr-when="fill_type=linear">{{ __('Angle') }}<input type="range" name="angle" min="0" max="360" step="5" value="{{ $d['angle'] }}"></label>
                            </div>
                        </fieldset>
                        <div>
                            <label class="flex min-h-11 items-center gap-2 text-sm font-bold text-navy-800"><input type="checkbox" name="eye_custom" @checked($d['eye_custom']) class="size-5 accent-brand-700">{{ __('Different colours for the corner eyes') }}</label>
                            <div class="mt-2 flex flex-wrap gap-4" data-qr-when="eye_custom">
                                <label class="flex items-center gap-2 text-sm font-semibold text-navy-700"><input type="color" name="eye_frame_color" value="{{ $d['eye_frame_color'] }}">{{ __('Eye frame') }}</label>
                                <label class="flex items-center gap-2 text-sm font-semibold text-navy-700"><input type="color" name="eye_ball_color" value="{{ $d['eye_ball_color'] }}">{{ __('Eye centre') }}</label>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <label class="flex items-center gap-2 text-sm font-bold text-navy-800"><input type="color" name="bg_color" value="{{ $d['bg_color'] }}">{{ __('Background') }}</label>
                            <label class="flex min-h-11 items-center gap-2 text-sm font-semibold text-navy-700"><input type="checkbox" name="bg_transparent" class="size-5 accent-brand-700">{{ __('Transparent background') }}</label>
                        </div>
                    </div>
                </details>

                {{-- logo --}}
                <details class="rounded-3xl border border-navy-100 bg-white">
                    <summary class="flex min-h-14 cursor-pointer items-center justify-between gap-3 px-4 text-base font-black text-navy-900 sm:px-6">{{ __('Logo in the middle') }}<svg class="qr-chev size-5 text-navy-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg></summary>
                    <div class="grid gap-5 border-t border-navy-100 px-4 py-5 sm:px-6">
                        <fieldset>
                            <legend class="field-label">{{ __('Pick an icon or upload your logo') }}</legend>
                            <div class="grid grid-cols-4 gap-2 sm:grid-cols-8">
                                <label class="flex min-h-16 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-navy-200 p-1.5 text-[11px] font-bold text-navy-700 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"><input type="radio" name="logo" value="none" checked class="sr-only"><span class="grid size-7 place-items-center rounded-full border-2 border-dashed border-navy-300 text-navy-400">∅</span>{{ __('None') }}</label>
                                @foreach ($icons as $icon => $label)
                                    <label class="flex min-h-16 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-navy-200 p-1.5 text-[11px] font-bold text-navy-700 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-600"><input type="radio" name="logo" value="{{ $icon }}" class="sr-only"><span data-qr-icon="{{ $icon }}" class="size-7"></span>{{ $label }}</label>
                                @endforeach
                                <label class="flex min-h-16 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-navy-200 p-1.5 text-[11px] font-bold text-navy-700 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:disabled]:opacity-50"><input type="radio" name="logo" value="upload" disabled class="sr-only"><x-landing.icon name="upload" class="size-7 text-navy-500" />{{ __('My logo') }}</label>
                            </div>
                        </fieldset>
                        <div>
                            <label class="btn-ghost relative min-h-11 cursor-pointer rounded-full focus-within:outline-2 focus-within:outline-brand-600">
                                <x-landing.icon name="upload" class="size-5" />{{ __('Upload your logo') }}
                                <input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif" data-qr-logo-file class="absolute inset-0 cursor-pointer opacity-0" aria-label="{{ __('Upload your logo') }}">
                            </label>
                            <p class="muted mt-2">{{ __('PNG, JPG, WebP or SVG up to 5 MB. It stays on your device and is never uploaded.') }}</p>
                            <p data-qr-logo-error hidden class="field-error">{{ __('Pick an image file (PNG, JPG, WebP or SVG) up to 5 MB.') }}</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block text-sm font-bold text-navy-800">{{ __('Logo size') }}<input type="range" name="logo_size" min="10" max="40" step="1" value="{{ $d['logo_size'] }}"></label>
                            <div class="grid gap-1">
                                <label class="flex min-h-11 items-center gap-2 text-sm font-semibold text-navy-700"><input type="checkbox" name="logo_plate" @checked($d['logo_plate']) class="size-5 accent-brand-700">{{ __('Background plate behind the logo') }}</label>
                                <label class="flex min-h-11 items-center gap-2 text-sm font-semibold text-navy-700"><input type="checkbox" name="logo_clear" @checked($d['logo_clear']) class="size-5 accent-brand-700">{{ __('Clear the dots behind the logo') }}</label>
                            </div>
                        </div>
                        <p class="muted">{{ __('With a logo, error correction goes up to the highest level (H) so the code still scans.') }}</p>
                    </div>
                </details>

                {{-- shapes --}}
                <details open class="rounded-3xl border border-navy-100 bg-white">
                    <summary class="flex min-h-14 cursor-pointer items-center justify-between gap-3 px-4 text-base font-black text-navy-900 sm:px-6">{{ __('Shapes') }}<svg class="qr-chev size-5 text-navy-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg></summary>
                    <div class="grid gap-5 border-t border-navy-100 px-4 py-5 sm:px-6">
                        @foreach ([['body', __('Body shape'), $bodyShapes, 'body'], ['eye_frame', __('Eye frame shape'), $frameShapes, 'frame'], ['eye_ball', __('Eye centre shape'), $ballShapes, 'ball']] as [$name, $legend, $options, $kind])
                            <fieldset>
                                <legend class="field-label">{{ $legend }}</legend>
                                <div class="grid grid-cols-4 gap-2 sm:grid-cols-8">
                                    @foreach ($options as $value => $label)
                                        <label class="flex cursor-pointer flex-col items-center gap-1 rounded-xl border border-navy-200 p-2 text-[11px] leading-tight font-bold text-navy-700 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:text-navy-900 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-600">
                                            <input type="radio" name="{{ $name }}" value="{{ $value }}" @checked($d[$name] === $value) class="sr-only">
                                            <span data-qr-thumb="{{ $kind }}:{{ $value }}" class="block size-10 text-navy-800"></span>
                                            <span class="text-center">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach
                    </div>
                </details>

                {{-- frame --}}
                <details class="rounded-3xl border border-navy-100 bg-white">
                    <summary class="flex min-h-14 cursor-pointer items-center justify-between gap-3 px-4 text-base font-black text-navy-900 sm:px-6">{{ __('Frame and label') }}<svg class="qr-chev size-5 text-navy-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg></summary>
                    <div class="grid gap-5 border-t border-navy-100 px-4 py-5 sm:px-6">
                        <fieldset>
                            <legend class="field-label">{{ __('Frame style') }}</legend>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach ($frames as $value => $label)
                                    <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-navy-200 px-3 text-sm font-bold text-navy-700 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-600"><input type="radio" name="frame" value="{{ $value }}" @checked($d['frame'] === $value) class="sr-only">{{ $label }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end" data-qr-when="frame=bottom|top|pill">
                            <label class="block"><span class="field-label">{{ __('Label text') }}</span><input name="frame_text" class="field" maxlength="40" value="{{ $d['frame_text'] }}" dir="auto"></label>
                            <label class="flex items-center gap-2 text-sm font-semibold text-navy-700"><input type="color" name="frame_color" value="{{ $d['frame_color'] }}">{{ __('Frame') }}</label>
                            <label class="flex items-center gap-2 text-sm font-semibold text-navy-700"><input type="color" name="frame_text_color" value="{{ $d['frame_text_color'] }}">{{ __('Text') }}</label>
                        </div>
                    </div>
                </details>

                {{-- advanced --}}
                <details class="rounded-3xl border border-navy-100 bg-white">
                    <summary class="flex min-h-14 cursor-pointer items-center justify-between gap-3 px-4 text-base font-black text-navy-900 sm:px-6">{{ __('Quality and margin') }}<svg class="qr-chev size-5 text-navy-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg></summary>
                    <div class="grid gap-5 border-t border-navy-100 px-4 py-5 sm:grid-cols-2 sm:px-6">
                        <label class="block"><span class="field-label">{{ __('Error correction') }}</span>
                            <select name="ecc" class="field">
                                <option value="L">L · 7%</option>
                                <option value="M" selected>M · 15%</option>
                                <option value="Q">Q · 25%</option>
                                <option value="H">H · 30%</option>
                            </select>
                            <span data-qr-ecc-note hidden class="mt-1 block text-xs font-semibold text-brand-800">{{ __('Set to H because the code has a logo.') }}</span>
                        </label>
                        <label class="block"><span class="field-label">{{ __('Margin around the code (quiet zone)') }}</span>
                            <input type="range" name="margin" min="2" max="8" step="1" value="4">
                            <span class="flex justify-between text-xs text-navy-500"><span>{{ __('Thin') }}</span><span>{{ __('Wide') }}</span></span>
                        </label>
                        <p class="muted sm:col-span-2">{{ __('Higher error correction survives scratches and logos but makes the dots smaller. Keep a margin of at least 2 dots around the code.') }}</p>
                    </div>
                </details>
            </div>
        </form>

        <button type="button" class="qr-mini" aria-label="{{ __('Back to the preview') }}"><span data-qr-mini class="block"></span></button>
    </div>
</section>

{{-- how it works + FAQ (also what search engines read) --}}
<section class="py-12 lg:py-16">
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <h2 class="lp-h2 text-center">{{ __('Your QR code in three steps') }}</h2>
        <ol class="mt-8 grid gap-4 sm:grid-cols-3">
            @foreach ([[__('Add the content'), __('A link, a WhatsApp number, your Wi-Fi or a location.')], [__('Style it'), __('Shapes, colours, your logo and a "Scan me" label.')], [__('Download and test'), __('PNG, SVG or PDF. Scan it with your phone before you print.')]] as $i => [$stepTitle, $stepText])
                <li class="lp-idea">
                    <span class="lp-idea-icon text-xl font-black">{{ $i + 1 }}</span>
                    <h3 class="mt-4 text-lg font-black text-navy-900">{{ $stepTitle }}</h3>
                    <p class="mt-1 text-navy-600">{{ $stepText }}</p>
                </li>
            @endforeach
        </ol>

        <h2 class="lp-h2 mt-16 text-center">{{ __('Frequently asked questions') }}</h2>
        <div class="mx-auto mt-8 max-w-3xl space-y-3">
            @foreach ($faq as [$q, $a])
                <details name="qr-faq" class="lp-faq group rounded-2xl border border-navy-100 bg-white px-5 open:bg-[var(--lp-paper)]">
                    <summary class="flex min-h-15 cursor-pointer list-none items-center justify-between gap-4 py-4 text-[1.02rem] font-extrabold">
                        {{ $q }}
                        <span class="lp-faq-icon grid size-8 shrink-0 place-items-center rounded-full border border-navy-200 bg-white text-lg leading-none text-navy-600">+</span>
                    </summary>
                    <p class="pb-5 leading-relaxed text-navy-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- the offer: from one QR code to a whole QR menu --}}
<section class="lp-band lp-glow mb-8 py-14 lg:py-20" data-qr-cta>
    <div class="mx-auto grid max-w-5xl items-center gap-8 px-4 sm:px-6 lg:grid-cols-[1.4fr_1fr]">
        <div class="text-center lg:text-start">
            <p class="lp-eyebrow">{{ __('For restaurants and cafes') }}</p>
            <h2 class="lp-h2 mt-3">{{ __('Want a full QR menu for your restaurant? EgHub makes it free') }}</h2>
            <p class="lp-lead mt-3">{{ __('One QR on every table opens your menu with photos and prices in Arabic and English. Change a price any time without reprinting, and take orders from the table without commission.') }}</p>
            <div class="mt-6 flex flex-wrap justify-center gap-3 lg:justify-start">
                <a href="{{ route('register') }}" class="btn-primary min-h-12 rounded-full px-7 text-base">{{ __('Make my free menu') }}</a>
                <a href="{{ route('features.show', 'qr-menu') }}" class="btn-ghost min-h-12 rounded-full px-6 text-base">{{ __('See the QR menu') }}</a>
            </div>
            <p class="mt-3 text-sm text-navy-500">{{ __('No card, no contract.') }}</p>
        </div>
        <ul class="grid gap-3 text-sm font-bold text-navy-800">
            @foreach ([__('A QR for every table, ready to print'), __('Update the menu without new codes'), __('Orders and WhatsApp without commission')] as $point)
                <li class="flex items-center gap-3 rounded-2xl border border-navy-100 bg-white px-4 py-3"><x-landing.icon name="check" class="size-5 shrink-0 text-emerald-600" />{{ $point }}</li>
            @endforeach
        </ul>
    </div>
</section>
@endsection
