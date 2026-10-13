@extends('layouts.dashboard')
@section('title', __('Upgrade your plan'))

@section('content')
<div class="card mx-auto max-w-lg text-center">
    <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-brand-100 text-brand-700">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
    </div>
    <h1 class="mb-2 text-xl font-extrabold">{{ __('Upgrade your plan') }}</h1>
    <p class="text-navy-500">{{ $message }}</p>
</div>
@endsection
