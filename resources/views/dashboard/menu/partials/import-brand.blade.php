{{--
    "Brand style found in your file" on the import review (AI imports): the logo cut out of the file, the colours, the
    heading font and the language (App\Services\Import\BrandStyle). Applying the import applies it while the box stays
    ticked; without the full_branding plan feature the card is shown disabled with the upgrade note.
--}}
@php
    $swatches = array_filter([
        __('Main color') => $brand['primary'], __('Header color') => $brand['secondary'],
        __('Background color') => $brand['background'], __('Text color') => $brand['text'],
    ]);
    $languages = ['ar' => __('Arabic only'), 'en' => __('English only'), 'both' => __('Arabic and English')];
    $fontCss = $brand['font'] ? \App\Support\MenuFonts::cssUrl($brand['font']) : null;
@endphp
@if ($fontCss)
    <link rel="stylesheet" href="{{ $fontCss }}">
@endif
<section @class(['card relative overflow-hidden', 'opacity-75' => ! $allowed]) data-import-brand aria-labelledby="import-brand-title">
    <div class="pointer-events-none absolute inset-x-0 top-0 h-1.5" style="background: linear-gradient(90deg, {{ $brand['primary'] ?? '#F26522' }}, {{ $brand['secondary'] ?? $brand['primary'] ?? '#0A2540' }})" aria-hidden="true"></div>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="import-brand-title" class="section-title flex items-center gap-2">
                <svg class="size-5 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22a10 10 0 1 1 10-10c0 2.5-2 3.5-4 3.5h-1.5a1.5 1.5 0 0 0-1 2.6A1.6 1.6 0 0 1 14.4 21 9 9 0 0 1 12 22Z"/><circle cx="7.5" cy="10.5" r="1"/><circle cx="10.5" cy="7" r="1"/><circle cx="15" cy="7.5" r="1"/></svg>
                {{ __('Brand style found in your file') }}
            </h2>
            <p class="muted text-sm">{{ __('Your QR menu can take the same logo, colours and font as your printed menu.') }}</p>
        </div>
        @if ($allowed)
            <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-3 text-sm font-bold text-brand-700">
                <input type="checkbox" name="apply_style" value="1" checked class="size-4 accent-brand-600" data-apply-style> {{ __('Apply this style to my menu') }}
            </label>
        @else
            <label class="flex min-h-11 cursor-not-allowed items-center gap-2 rounded-xl border border-navy-100 bg-navy-50 px-3 text-sm font-bold text-navy-500">
                <input type="checkbox" disabled class="size-4"> {{ __('Apply this style to my menu') }}
            </label>
        @endif
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-navy-100 p-3">
            <span class="field-label">{{ __('Logo') }}</span>
            @if ($brand['logo'])
                <div class="grid h-28 place-items-center rounded-xl bg-[length:16px_16px] p-2" style="background-color: #fff; background-image: linear-gradient(45deg, #EEF2F7 25%, transparent 25%, transparent 75%, #EEF2F7 75%), linear-gradient(45deg, #EEF2F7 25%, transparent 25%, transparent 75%, #EEF2F7 75%); background-position: 0 0, 8px 8px;">
                    <img src="/storage/{{ $brand['logo'] }}" alt="{{ __('Logo') }}" class="max-h-24 max-w-full object-contain" data-brand-logo>
                </div>
                @if ($allowed && $hasLogo)
                    <label class="mt-2 flex items-center gap-2 text-xs font-semibold"><input type="checkbox" name="replace_logo" value="1" class="size-4 accent-brand-600" data-replace-logo> {{ __('Replace my logo') }}</label>
                @elseif ($allowed)
                    <p class="mt-2 text-xs text-navy-500">{{ __('Becomes your menu logo.') }}</p>
                @endif
            @else
                <p class="grid h-28 place-items-center rounded-xl bg-navy-50 px-3 text-center text-xs text-navy-500">{{ __('No logo was found in the file.') }}</p>
            @endif
        </div>

        <div class="rounded-2xl border border-navy-100 p-3">
            <span class="field-label">{{ __('Colors') }}</span>
            @if ($swatches)
                <ul class="grid grid-cols-2 gap-2">
                    @foreach ($swatches as $label => $hex)
                        <li class="flex items-center gap-2">
                            <span class="size-9 shrink-0 rounded-full shadow-inner ring-1 ring-navy-200" style="background: {{ $hex }}" data-brand-swatch="{{ $hex }}"></span>
                            <span class="min-w-0 text-xs leading-tight"><b class="block truncate">{{ $label }}</b><code dir="ltr" class="text-navy-500">{{ $hex }}</code></span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-xs text-navy-500">{{ __('No clear colours in the file.') }}</p>
            @endif
        </div>

        <div class="rounded-2xl border border-navy-100 p-3">
            <span class="field-label">{{ __('Font') }}</span>
            @if ($brand['font'])
                <p class="truncate text-3xl leading-tight" style="font-family: '{{ $brand['font'] }}', 'Cairo', sans-serif">Aa · أبجد</p>
                <p class="mt-1 text-sm font-bold" data-brand-font>{{ $brand['font'] }}</p>
            @else
                <p class="text-xs text-navy-500">{{ __('Your current font stays.') }}</p>
            @endif
        </div>

        <div class="rounded-2xl border border-navy-100 p-3">
            <span class="field-label">{{ __('Menu language') }}</span>
            @if ($brand['language'])
                <p class="text-lg font-extrabold" data-brand-language="{{ $brand['language'] }}">{{ $languages[$brand['language']] }}</p>
                @if ($brand['language'] === 'en')
                    <p class="mt-1 text-xs text-navy-500">{{ __('Guests will see the menu in English first.') }}</p>
                @endif
            @else
                <p class="text-xs text-navy-500">{{ __('Not sure.') }}</p>
            @endif
        </div>
    </div>

    @unless ($allowed)
        <p class="mt-4 rounded-xl bg-brand-50 p-3 text-sm text-brand-700" data-brand-locked>{{ \App\Services\Entitlements::current()->upgradeMessage('full_branding') }}</p>
    @endunless
</section>
