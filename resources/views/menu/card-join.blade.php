@extends('layouts.base')
@section('title', tr($program->title, $program->title_en))
@section('body_class', 'bg-navy-50 text-navy-900')

@section('body')
<main class="mx-auto max-w-md px-4 py-6">
    @include('partials.loyalty-card', ['stamps' => 0])

    <div class="card mt-6">
        <h1 class="section-title">{{ __('Get your free loyalty card') }}</h1>
        <ul class="mt-2 mb-4 space-y-1 text-sm text-navy-700">
            @foreach ($milestones as $m)
                <li>🎁 {{ $m['label'] }} — {{ $program->type === 'points' ? __(':n points', ['n' => $m['at']]) : __('Stamp :n', ['n' => $m['at']]) }}</li>
            @endforeach
        </ul>
        <x-flash />
        <form method="post" action="{{ route('menu.card.store') }}" class="space-y-3">
            @csrf
            <input class="field" name="phone" value="{{ old('phone') }}" required inputmode="tel" dir="ltr" placeholder="{{ __('Mobile number') }} 01xxxxxxxxx" autocomplete="tel">
            <input class="field" name="name" value="{{ old('name') }}" maxlength="100" placeholder="{{ __('Your name (optional)') }}" autocomplete="name">
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="consent" value="1" class="mt-1 size-4 accent-brand-600"> {{ __('Send me offers on WhatsApp') }}</label>
            <button class="btn-primary w-full">{{ __('Get my card') }}</button>
        </form>
    </div>
    <a href="{{ route('menu.show') }}" class="btn-ghost mt-4 w-full">{{ __('Open the menu') }}</a>
</main>
@endsection
