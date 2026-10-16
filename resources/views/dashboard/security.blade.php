@extends('layouts.dashboard')
@section('title', __('Account security'))

@section('content')
<h1 class="page-title mb-1">{{ __('Account security') }}</h1>
<p class="muted mb-4" dir="ltr">{{ $user->email ?? $user->phone }}</p>
@include('auth.two-factor-settings')
@endsection
