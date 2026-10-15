@extends('layouts.landing')
@section('title', __('Services') . ' · EgMenu')
@section('description', __('One-time services from EgWeb for cafes and restaurants: menu setup and data entry, food photography, QR table stands, stickers, printed menus, staff training and a custom domain.'))

@php
    $sent = session('service_sent');
    $chosen = old('service', $selected);
@endphp

@section('page')
<section data-hero class="border-b border-navy-100 bg-[var(--lp-paper)]">
    <div class="mx-auto max-w-7xl px-4 pt-12 pb-10 sm:px-6 lg:pt-16">
        <p class="lp-eyebrow">{{ __('Services') }}</p>
        <h1 class="mt-3 max-w-3xl text-[2rem] leading-[1.3] font-black tracking-tight sm:text-5xl sm:leading-[1.25]">{{ __('We set it up for you, once') }}</h1>
        <p class="lp-lead mt-4 max-w-2xl">{{ __('The subscription runs your menu every day. These are one-time jobs by the EgWeb team: we enter your menu, take the photos, print what goes on the tables and train your staff.') }}</p>
        <nav class="mt-8 flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]" aria-label="{{ __('Services') }}">
            @foreach ($services as $s)
                <a href="#{{ $s['key'] }}" class="flex min-h-11 shrink-0 items-center gap-2 rounded-full border border-navy-200 bg-white px-4 text-sm font-bold text-navy-800 hover:border-navy-900"><x-landing.icon :name="$s['icon']" class="size-4 text-brand-600" />{{ $s['name'] }}</a>
            @endforeach
        </nav>
    </div>
</section>

<section class="py-14 lg:py-20">
    <div class="mx-auto grid max-w-7xl gap-5 px-4 sm:px-6 lg:grid-cols-2">
        @foreach ($services as $s)
            <article id="{{ $s['key'] }}" class="flex scroll-mt-24 flex-col rounded-2xl border border-navy-200 bg-white p-6 sm:p-7" data-service="{{ $s['key'] }}" data-reveal style="--d: {{ ($loop->index % 2) * 60 }}ms">
                <div class="flex items-start gap-4">
                    <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-brand-50 text-brand-600"><x-landing.icon :name="$s['icon']" class="size-6" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-xl font-extrabold text-navy-900">{{ $s['name'] }}</h2>
                        <p class="mt-1 text-navy-600">{{ $s['summary'] }}</p>
                    </div>
                </div>

                <ul class="mt-5 space-y-2 text-sm text-navy-700">
                    @foreach ($s['includes'] as $line)
                        <li class="flex gap-2"><x-landing.icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-600" />{{ $line }}</li>
                    @endforeach
                </ul>

                @if ($s['items'])
                    <div class="mt-5 overflow-hidden rounded-xl border border-navy-100">
                        <table class="w-full text-sm">
                            <thead class="bg-navy-50 text-xs text-navy-500"><tr><th class="px-3 py-2 text-start font-bold">{{ __('Product') }}</th><th class="px-3 py-2 text-start font-bold">{{ __('Size') }}</th><th class="px-3 py-2 text-end font-bold">{{ __('From') }}</th></tr></thead>
                            <tbody>
                                @foreach ($s['items'] as [$product, $size, $price])
                                    <tr class="border-t border-navy-50">
                                        <td class="px-3 py-2 font-semibold">{{ $product }}</td>
                                        <td class="px-3 py-2 text-navy-500" dir="ltr">{{ $size }}</td>
                                        <td class="px-3 py-2 text-end font-bold whitespace-nowrap">{{ money($price) }} {{ __('EGP') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="mt-auto pt-6">
                    <div class="flex flex-wrap items-end justify-between gap-3 border-t border-navy-100 pt-5">
                        <div>
                            @if ($s['price'] !== null)
                                <div class="text-sm text-navy-500">{{ __('From') }}</div>
                                <div><b class="text-2xl font-black text-navy-900">{{ money($s['price']) }}</b> <span class="text-sm text-navy-500">{{ __('EGP') }} {{ $s['unit'] }}</span></div>
                            @elseif ($s['link'])
                                <a href="{{ $s['link'] }}" target="_blank" rel="noopener" class="text-lg font-black text-brand-700 hover:text-brand-600">{{ __('Prices on egweb.com.eg') }}</a>
                            @else
                                <div class="text-lg font-black text-navy-900">{{ __('Price on request') }}</div>
                            @endif
                            <div class="mt-1 flex items-center gap-1.5 text-sm text-navy-500"><x-landing.icon name="clock" class="size-4" />{{ $s['delivery'] }}</div>
                        </div>
                        <a href="{{ route('services', ['service' => $s['key']]) }}#request" data-pick-service="{{ $s['key'] }}" class="btn-primary min-h-11 px-5">{{ __('Request this service') }}</a>
                    </div>
                    @if ($s['note'])<p class="mt-3 text-xs text-navy-500">{{ $s['note'] }}</p>@endif
                </div>
            </article>
        @endforeach
    </div>
</section>

<section id="request" class="scroll-mt-20 border-t border-navy-100 bg-[var(--lp-paper)] py-14 lg:py-20">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[1fr_1.3fr]">
        <div>
            <h2 class="lp-h2">{{ __('Request a service') }}</h2>
            <p class="mt-3 text-navy-600">{{ __('Tell us what you need. We call you back within one working day with the price and the time, and nothing is due before you agree.') }}</p>
            <ul class="mt-6 space-y-3 text-sm text-navy-700">
                <li class="flex gap-2"><x-landing.icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-600" />{{ __('You don\'t need an EgMenu subscription to order a service.') }}</li>
                <li class="flex gap-2"><x-landing.icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-600" />{{ __('Pay by InstaPay, Vodafone Cash or cash on delivery.') }}</li>
            </ul>
            @if ($whatsappUrl)
                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="btn-ghost mt-6 min-h-11"><x-landing.icon name="chat" class="size-5 text-emerald-600" />{{ __('Or ask us on WhatsApp') }}</a>
            @endif
        </div>

        <div class="rounded-3xl border border-navy-200 bg-white p-6 sm:p-8">
            @if ($sent)
                <div class="text-center" data-service-sent role="status">
                    <span class="mx-auto grid size-14 place-items-center rounded-full bg-emerald-100 text-emerald-700"><x-landing.icon name="check" class="size-7" /></span>
                    <h3 class="mt-4 text-2xl font-black">{{ __('Thank you, we got your request') }}</h3>
                    <p class="mt-2 text-navy-600">{{ __('Our team will call you about “:service” within one working day.', ['service' => $sent]) }}</p>
                    @if ($whatsappUrl)
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="btn-primary mt-6 min-h-12 px-6">{{ __('Talk to us on WhatsApp now') }}</a>
                    @endif
                    <div class="mt-4"><a href="{{ route('services') }}#request" class="text-sm font-bold text-brand-700 hover:text-brand-600">{{ __('Request another service') }}</a></div>
                </div>
            @else
                <form method="post" action="{{ route('services.request') }}" class="grid gap-4 sm:grid-cols-2" novalidate>
                    @csrf
                    @if ($errors->any())
                        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 sm:col-span-2" role="alert" data-errors>
                            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                        </div>
                    @endif
                    <label class="block sm:col-span-2">
                        <span class="field-label">{{ __('Service') }}</span>
                        <select name="service" class="field" required data-service-select>
                            <option value="">{{ __('Choose a service') }}</option>
                            @foreach ($services as $s)<option value="{{ $s['key'] }}" @selected($chosen === $s['key'])>{{ $s['name'] }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('Your name') }}</span>
                        <input name="name" value="{{ old('name') }}" class="field" required maxlength="100" autocomplete="name">
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('Restaurant or cafe') }}</span>
                        <input name="restaurant" value="{{ old('restaurant') }}" class="field" required maxlength="120" autocomplete="organization">
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('Mobile number') }}</span>
                        <input name="phone" value="{{ old('phone') }}" class="field" required maxlength="20" inputmode="tel" dir="ltr" placeholder="01xxxxxxxxx" autocomplete="tel">
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('Email (optional)') }}</span>
                        <input name="email" type="email" value="{{ old('email') }}" class="field" maxlength="190" dir="ltr" autocomplete="email">
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('Quantity (optional)') }}</span>
                        <input name="quantity" type="number" min="1" value="{{ old('quantity') }}" class="field" inputmode="numeric">
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="field-label">{{ __('Details (optional)') }}</span>
                        <textarea name="notes" class="field min-h-24 py-2" maxlength="1000" placeholder="{{ __('Sizes, number of items or dishes, your area, the best time to call...') }}">{{ old('notes') }}</textarea>
                    </label>
                    <div class="hidden" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
                    <button class="btn-primary min-h-12 text-base sm:col-span-2">{{ __('Send the request') }}</button>
                </form>
            @endif
        </div>
    </div>
</section>

<script>
    // "Request this service" picks it in the form without reloading; errors bring the form into view
    document.querySelectorAll('[data-pick-service]').forEach((a) => a.addEventListener('click', (e) => {
        const select = document.querySelector('[data-service-select]');
        if (!select) return;
        e.preventDefault();
        select.value = a.dataset.pickService;
        document.getElementById('request').scrollIntoView({ behavior: 'smooth' });
        setTimeout(() => select.form.querySelector('[name="name"]').focus({ preventScroll: true }), 400);
    }));
    if (document.querySelector('[data-errors]')) document.getElementById('request').scrollIntoView();
</script>
@endsection
