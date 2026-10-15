{{-- Extra menu languages of one item / category: a name + description per enabled language (tr[fr][name]...). --}}
@php
    $compact = $compact ?? false;
@endphp
<section @class(['card space-y-4' => ! $compact, 'space-y-3' => $compact]) data-translations>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 @class(['text-lg font-bold' => ! $compact, 'text-sm font-bold' => $compact])>{{ __('Extra languages') }}</h2>
            @unless ($compact)
                <p class="text-sm text-navy-500">{{ __('Empty fields show the English text, then the Arabic.') }}</p>
            @endunless
        </div>
        @unless ($compact)
            <a href="{{ route('dash.menu') }}#ai-translate" class="text-sm font-semibold text-brand-700 underline underline-offset-2">✨ {{ __('Translate with AI') }}</a>
        @endunless
    </div>
    @foreach ($extraLocales as $code)
        <fieldset @class(['grid gap-3', 'sm:grid-cols-2' => ! $compact])>
            <legend class="mb-1 text-sm font-bold text-navy-700"><span lang="{{ $code }}">{{ \App\Support\MenuLocales::native($code) }}</span> · {{ \App\Support\MenuLocales::label($code) }}</legend>
            <input class="field" lang="{{ $code }}" dir="{{ \App\Support\MenuLocales::dir($code) }}" name="tr[{{ $code }}][name]" value="{{ old("tr.$code.name", $translations[$code]['name'] ?? '') }}" maxlength="100"
                   placeholder="{{ __('Name') }}" aria-label="{{ __('Name') }} ({{ \App\Support\MenuLocales::native($code) }})">
            <textarea @class(['field py-2', 'min-h-11' => ! $compact, 'min-h-16' => $compact]) lang="{{ $code }}" dir="{{ \App\Support\MenuLocales::dir($code) }}" name="tr[{{ $code }}][description]" maxlength="{{ $descriptionMax }}"
                      placeholder="{{ __('Description') }}" aria-label="{{ __('Description') }} ({{ \App\Support\MenuLocales::native($code) }})">{{ old("tr.$code.description", $translations[$code]['description'] ?? '') }}</textarea>
        </fieldset>
    @endforeach
</section>
