@extends('layouts.dashboard')
@section('title', __('Account security'))

@section('content')
<x-dash.page-header :title="__('Account security')" />
<p class="muted mb-4" dir="ltr">{{ $user->email ?? $user->phone }}</p>
@include('auth.two-factor-settings')
@endsection
