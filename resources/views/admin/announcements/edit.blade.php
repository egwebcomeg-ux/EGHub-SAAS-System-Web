@extends('layouts.admin')
@section('title', __('Edit announcement') . ' · EgHub Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="page-title">{{ __('Edit announcement') }}</h1>
    <a href="{{ route('admin.announcements') }}" class="btn-ghost">{{ __('All announcements') }}</a>
</div>
<div class="max-w-3xl">
    @include('admin.announcements.form')
</div>
@endsection
