@extends('layouts.base')
@section('title', __('My balance') . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'bg-navy-50 text-navy-900')

@push('head')
<meta name="robots" content="noindex">
@endpush

@section('body')
{{-- The card page of a restaurant without a stamp program: balance / membership / packages, QR for the cashier, history. --}}
<main class="mx-auto max-w-md px-4 py-6">
    <div class="card text-center">
        @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="mx-auto mb-2 size-14 rounded-xl object-contain">@endif
        <div class="font-extrabold">{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</div>
        @if ($customer->name)<div class="text-sm text-navy-500">{{ $customer->name }}</div>@endif
        <div class="mx-auto mt-4 w-48 [&_svg]:h-auto [&_svg]:w-full">{!! $qr !!}</div>
    </div>
    @if ($wallet)
        @include('menu.partials.wallet-balance')
    @endif
    @include('menu.partials.member-packages')
    @include('menu.partials.member-vouchers')
    <a href="{{ route('menu.show') }}" class="btn-ghost mt-6 w-full">{{ __('Open the menu') }}</a>
</main>
@endsection
