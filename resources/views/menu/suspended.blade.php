@extends('layouts.base')
@section('title', $tenant->name)

@section('body')
<div class="flex min-h-screen items-center justify-center px-4 text-center">
    <div>
        <h1 class="mb-2 text-2xl font-extrabold">{{ $tenant->name }}</h1>
        <p class="text-navy-500">{{ __('This menu is temporarily unavailable.') }}</p>
    </div>
</div>
@endsection
