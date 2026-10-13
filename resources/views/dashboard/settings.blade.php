@extends('layouts.dashboard')
@section('title', __('Settings'))

@section('content')
<h1 class="mb-4 text-2xl font-extrabold">{{ __('Settings') }}</h1>

<form method="post" action="{{ route('dash.settings.update') }}" enctype="multipart/form-data" class="space-y-4">
    @csrf
    <section class="card grid gap-4 sm:grid-cols-2">
        <h2 class="text-lg font-bold sm:col-span-2">{{ __('Restaurant identity') }}</h2>
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

    <button class="btn-primary">{{ __('Save settings') }}</button>
</form>
@endsection
