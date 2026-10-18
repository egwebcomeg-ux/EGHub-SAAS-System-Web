@extends('layouts.admin')
@section('title', __('Branding & look') . ' · EgMenu Admin')

@section('content')
@php
    $colors = [
        'brand_color_primary' => [__('Primary color'), __('Buttons, links and highlights')],
        'brand_color_secondary' => [__('Secondary color'), __('The "menu" of the wordmark and blue accents')],
        'brand_color_navy' => [__('Dark color'), __('Sidebars, footer and dark surfaces')],
    ];
    $uploads = [
        'logo' => ['brand_logo', __('Logo for light backgrounds'), __('PNG or WebP with a transparent background, about 600 × 160.'), 'bg-white'],
        'logo_on_dark' => ['brand_logo_on_dark', __('Logo for dark backgrounds'), __('A white or light version, used on the navy sidebar and footer.'), 'bg-navy-800'],
        'favicon' => ['brand_favicon', __('Favicon'), __('Square, at least 64 × 64. Shown in the browser tab.'), 'bg-white'],
    ];
@endphp
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Branding & look') }}</h1>
        <p class="muted">{{ __('The name, logo and colors of the platform: the website, sign-in pages and dashboards. Restaurant menus keep their own colors.') }}</p>
    </div>
    <form method="post" action="{{ route('admin.branding.reset') }}" onsubmit="return confirm(@js(__('Go back to the EgMenu name, logo and colors? Uploaded logos are removed.')))">
        @csrf<button class="btn-ghost">{{ __('Reset to EgMenu defaults') }}</button>
    </form>
</div>

@if (session('brand_warning'))
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800" role="alert">{{ session('brand_warning') }}</div>
@endif

<form method="post" action="{{ route('admin.branding.update') }}" enctype="multipart/form-data" class="grid gap-4 lg:grid-cols-5" data-branding-form>
    @csrf
    <div class="space-y-4 lg:col-span-3">
        <section class="card space-y-3">
            <h2 class="section-title">{{ __('Site name') }}</h2>
            <input class="field" name="brand_site_name" value="{{ old('brand_site_name', $brand['brand_site_name']) }}" required maxlength="40" aria-label="{{ __('Site name') }}" data-preview-name>
            <p class="text-xs text-navy-500">{{ __('Used in page titles and next to the logo.') }}</p>
        </section>

        <section class="card">
            <h2 class="section-title mb-3">{{ __('Colors') }}</h2>
            <div class="space-y-3">
                @foreach ($colors as $key => [$label, $hint])
                    @php($value = old($key, $brand[$key]))
                    <div class="flex flex-wrap items-center gap-3">
                        <input type="color" value="{{ $value }}" class="h-11 w-14 shrink-0 cursor-pointer rounded-xl border border-navy-100 bg-white p-1" aria-label="{{ $label }}" data-color-picker="{{ $key }}">
                        <div class="min-w-0 flex-1">
                            <label class="block text-sm font-bold" for="c-{{ $key }}">{{ $label }}</label>
                            <div class="text-xs text-navy-500">{{ $hint }} · {{ __('Default') }} <span dir="ltr">{{ $defaults[$key] }}</span></div>
                        </div>
                        <input class="field w-32" id="c-{{ $key }}" name="{{ $key }}" value="{{ $value }}" required pattern="#[0-9a-fA-F]{6}" maxlength="7" dir="ltr" data-color-text="{{ $key }}">
                    </div>
                @endforeach
            </div>
            <div @class(['mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800', 'hidden' => $contrast >= 3]) data-contrast-warning>
                {{ __('Low contrast: white text on this primary color is hard to read. Pick a darker shade (aim for a ratio of 3 or more). Now:') }} <span dir="ltr" class="font-bold" data-contrast-ratio>{{ $contrast }}</span>
            </div>
        </section>

        <section class="card">
            <h2 class="section-title mb-3">{{ __('Logo & favicon') }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ($uploads as $field => [$key, $label, $hint, $bg])
                    @php($url = \App\Support\PlatformBrand::url($key))
                    <div class="space-y-2">
                        <div class="text-sm font-bold">{{ $label }}</div>
                        <div class="grid h-24 place-items-center rounded-xl border border-navy-100 {{ $bg }} p-3">
                            @if ($url)
                                <img src="{{ $url }}" alt="" class="max-h-full max-w-full object-contain" data-upload-preview="{{ $field }}">
                            @elseif ($field === 'favicon')
                                <img src="{{ versioned_asset('favicon.svg') }}" alt="" class="size-10" data-upload-preview="{{ $field }}">
                            @else
                                <span data-upload-preview="{{ $field }}"><x-logo :light="$field === 'logo_on_dark'" :size="30" /></span>
                            @endif
                        </div>
                        <input type="file" name="{{ $field }}" accept="image/png,image/jpeg,image/webp" class="block w-full text-xs file:me-2 file:rounded-lg file:border-0 file:bg-navy-100 file:px-3 file:py-2 file:text-sm file:font-semibold" data-upload="{{ $field }}">
                        <p class="text-xs text-navy-500">{{ $hint }}</p>
                        @if ($url)
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="remove_{{ $field }}" value="1" class="size-4 accent-brand-600"> {{ __('Remove') }}</label>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    {{-- live preview of the picked colors (CSS variables set by the script below, nothing saved until "Save") --}}
    <aside class="lg:col-span-2">
        <div class="card sticky top-20 space-y-3" data-brand-preview>
            <h2 class="section-title">{{ __('Live preview') }}</h2>
            <div class="overflow-hidden rounded-2xl border border-navy-100" style="--p: {{ $brand['brand_color_primary'] }}; --s: {{ $brand['brand_color_secondary'] }}; --n: {{ $brand['brand_color_navy'] }}" data-preview-root>
                <div class="flex">
                    <div class="w-24 shrink-0 space-y-2 p-3" style="background: var(--n)">
                        <div class="h-2 rounded-full bg-white/70"></div>
                        <div class="h-6 rounded-lg bg-white/15" style="box-shadow: inset 3px 0 0 var(--p)"></div>
                        <div class="h-2 w-3/4 rounded-full bg-white/30"></div>
                        <div class="h-2 w-2/3 rounded-full bg-white/30"></div>
                    </div>
                    <div class="min-w-0 flex-1 space-y-3 bg-navy-50 p-4">
                        <div class="text-lg font-black leading-tight" dir="ltr"><span style="color: var(--p)">eg</span><span style="color: var(--s)">menu</span> <span class="text-xs font-semibold text-navy-500" data-preview-name-out>{{ $brand['brand_site_name'] }}</span></div>
                        <div class="rounded-xl bg-white p-3 text-sm shadow-sm">
                            <div class="font-bold text-navy-900">{{ __('A card title') }}</div>
                            <div class="text-navy-500">{{ __('Some text with') }} <span class="font-semibold" style="color: var(--p)">{{ __('a link') }}</span>.</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex min-h-10 items-center rounded-xl px-4 text-sm font-semibold text-white" style="background: var(--p)">{{ __('Main button') }}</span>
                            <span class="inline-flex min-h-10 items-center rounded-xl px-4 text-sm font-semibold text-white" style="background: var(--n)">{{ __('Dark button') }}</span>
                        </div>
                        <span class="badge" style="background: color-mix(in srgb, var(--p) 14%, white); color: var(--p)">{{ __('Badge') }}</span>
                    </div>
                </div>
            </div>
            <p class="text-xs text-navy-500">{{ __('Saved colors replace the theme colors on every page right away; no rebuild needed.') }}</p>
        </div>
    </aside>

    <div class="sticky bottom-0 z-10 -mx-4 flex flex-wrap gap-2 border-t border-navy-100 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border lg:col-span-5">
        <button class="btn-primary">{{ __('Save branding') }}</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    (() => {
        const form = document.querySelector('[data-branding-form]');
        const root = form.querySelector('[data-preview-root]');
        const vars = { brand_color_primary: '--p', brand_color_secondary: '--s', brand_color_navy: '--n' };
        const hex = /^#[0-9a-fA-F]{6}$/;
        const lum = (h) => {
            const c = [1, 3, 5].map((i) => parseInt(h.substr(i, 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
            return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
        };
        const contrastWithWhite = (h) => Math.round((1.05 / (lum(h) + 0.05)) * 100) / 100;
        const apply = (key, value) => {
            if (!hex.test(value)) { return; }
            root.style.setProperty(vars[key], value);
            if (key === 'brand_color_primary') {
                const ratio = contrastWithWhite(value);
                form.querySelector('[data-contrast-ratio]').textContent = ratio;
                form.querySelector('[data-contrast-warning]').classList.toggle('hidden', ratio >= 3);
            }
        };
        form.querySelectorAll('[data-color-picker]').forEach((picker) => {
            const key = picker.dataset.colorPicker;
            const text = form.querySelector(`[data-color-text="${key}"]`);
            picker.addEventListener('input', () => { text.value = picker.value.toUpperCase(); apply(key, picker.value); });
            text.addEventListener('input', () => { if (hex.test(text.value)) { picker.value = text.value; apply(key, text.value); } });
        });
        const name = form.querySelector('[data-preview-name]');
        name.addEventListener('input', () => { form.querySelector('[data-preview-name-out]').textContent = name.value; });
        // picked files show in their box before saving
        form.querySelectorAll('[data-upload]').forEach((input) => input.addEventListener('change', () => {
            const file = input.files[0];
            const box = form.querySelector(`[data-upload-preview="${input.dataset.upload}"]`);
            if (!file || !box) { return; }
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.className = 'max-h-full max-w-full object-contain';
            img.dataset.uploadPreview = input.dataset.upload;
            box.replaceWith(img);
        }));
    })();
</script>
@endpush
