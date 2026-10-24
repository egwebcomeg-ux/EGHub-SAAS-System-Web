@props(['title', 'updated', 'current'])
{{-- Shell for the legal pages: title band, a side card with the page's sections (filled by landing.js) and the text. --}}
<section class="lp-hero lp-glow">
    <div class="relative mx-auto max-w-6xl px-4 pt-8 pb-12 sm:pt-12 sm:pb-14">
        <nav class="flex items-center gap-2 text-sm text-navy-500" aria-label="{{ __('Breadcrumb') }}">
            <a href="{{ route('landing') }}" class="hover:text-navy-900">EgHub</a>
            <span aria-hidden="true">/</span>
            <span class="font-semibold text-navy-800">{{ $title }}</span>
        </nav>
        <h1 class="mt-3 text-3xl font-extrabold sm:text-4xl">{{ $title }}</h1>
        <p class="mt-2 text-sm text-navy-500">{{ $updated }}</p>
    </div>
</section>

<div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 lg:grid-cols-[16rem_minmax(0,1fr)]">
    <aside class="lg:sticky lg:top-24 lg:self-start">
        <div class="rounded-2xl border border-navy-100 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-3 gap-1 rounded-xl bg-navy-50 p-1 text-sm font-bold">
                @foreach (['terms' => __('Terms of service'), 'privacy' => __('Privacy policy'), 'refund' => __('Refund policy')] as $legalRoute => $legalLabel)
                    <a href="{{ route($legalRoute) }}" @class(['grid min-h-10 place-items-center rounded-lg px-1 text-center leading-tight', 'bg-white text-navy-900 shadow-sm' => $current === $legalRoute, 'text-navy-500 hover:text-navy-900' => $current !== $legalRoute])>{{ $legalLabel }}</a>
                @endforeach
            </div>
            <div class="mt-4 hidden text-xs font-bold text-navy-500 lg:block">{{ __('On this page') }}</div>
            <ol class="mt-2 hidden space-y-1 text-sm lg:block" data-toc></ol>
        </div>
        <a href="{{ route('register') }}" class="btn-primary mt-4 hidden w-full lg:flex">{{ __('Start free') }}</a>
    </aside>
    <article data-legal class="rounded-3xl border border-navy-100 bg-white p-6 leading-relaxed shadow-sm sm:p-10 [&_h2]:mt-9 [&_h2]:mb-3 [&_h2]:scroll-mt-24 [&_h2]:border-b [&_h2]:border-navy-50 [&_h2]:pb-2 [&_h2]:text-xl [&_h2]:font-extrabold [&_h2]:text-navy-900 [&_h2:first-of-type]:mt-0 [&_li]:mb-1.5 [&_li]:ms-5 [&_li]:list-disc [&_li]:text-navy-700 [&_li]:marker:text-brand-500 [&_p]:mb-3 [&_p]:text-navy-700">
        {{ $slot }}
        @if ($legalLines = \App\Support\LegalDetails::lines())
            <div class="mt-9 rounded-2xl bg-navy-50 p-4 text-sm text-navy-700" data-legal-details>
                @foreach ($legalLines as $line)
                    <div><b>{{ $line['label'] }}:</b> <span @if ($line['key'] !== 'legal_entity' && $line['key'] !== 'legal_address') dir="ltr" @endif>{{ $line['value'] }}</span></div>
                @endforeach
            </div>
        @endif
    </article>
</div>
