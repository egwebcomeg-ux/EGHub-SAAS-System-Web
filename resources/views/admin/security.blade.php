@extends('layouts.admin')
@section('title', __('Account security') . ' · EgMenu Admin')

@section('content')
<h1 class="page-title mb-1">{{ __('Account security') }}</h1>
<p class="muted mb-4" dir="ltr">{{ $user->email }}</p>
@include('auth.two-factor-settings')
@endsection
