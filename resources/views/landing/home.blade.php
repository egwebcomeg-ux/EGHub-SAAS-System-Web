@extends('layouts.base')
@section('title', 'EgMenu — ' . __('Digital QR menu for restaurants and cafes'))
@section('body_class', 'bg-white text-navy-900')

@section('body')
<header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5">
    <x-logo :size="36" />
    <div class="flex items-center gap-3 text-sm font-semibold">
        <a href="?lang={{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</a>
        <a href="{{ platform_url('app', '/login') }}" class="btn-ghost">{{ __('Restaurant login') }}</a>
    </div>
</header>

<section class="mx-auto max-w-6xl px-4 py-12 text-center">
    <h1 class="mx-auto max-w-3xl text-4xl font-extrabold leading-tight sm:text-5xl">{{ __('Digital QR menu for restaurants and cafes') }}</h1>
    <p class="mx-auto mt-4 max-w-2xl text-lg text-navy-500">{{ __('Table ordering, kitchen display, POS and a loyalty card — in one simple monthly subscription.') }}</p>
</section>

<section class="mx-auto grid max-w-6xl gap-4 px-4 pb-16 md:grid-cols-3">
    @foreach ($plans as $p)
        <div @class(['card relative flex flex-col', 'ring-2 ring-brand-500' => $p->is_featured])>
            @if ($p->is_featured)<span class="badge absolute -top-3 start-5 bg-brand-600 text-white">{{ __('Most popular') }}</span>@endif
            <h2 class="text-xl font-extrabold">{{ $p->displayName() }}</h2>
            <p class="mt-1 min-h-10 text-sm text-navy-500">{{ tr($p->tagline, $p->tagline_en) }}</p>
            <div class="my-4">
                @if ($p->original_price)<span class="me-2 text-navy-500 line-through">{{ money($p->original_price) }}</span>@endif
                <span class="text-4xl font-extrabold">{{ money($p->monthly_price) }}</span>
                <span class="text-sm text-navy-500">{{ __('EGP / month') }}</span>
            </div>
            <ul class="flex-1 space-y-1.5 text-sm">
                @foreach (\App\Models\Plan::LIMITS as $key => $_)
                    @php($v = $matrix[$p->id]['limits'][$key])
                    @continue($v === 0)
                    <li>• {{ \App\Models\Plan::label($key) }}: <b>{{ $v === null ? __('Unlimited') : $v }}</b></li>
                @endforeach
                @foreach ($matrix[$p->id]['features'] as $key => $on)
                    @if ($on)<li class="text-navy-700">✓ {{ \App\Models\Plan::label($key) }}</li>@endif
                @endforeach
            </ul>
        </div>
    @endforeach
</section>

<footer class="border-t border-navy-100 py-6 text-center text-sm text-navy-500">© {{ date('Y') }} EgMenu · {{ __('by') }} EgWeb</footer>
@endsection
