@php
    // back to the form the visitor came from (same site only), so it loads again with a fresh security token
    $back = url()->previous();
    $back = parse_url($back, PHP_URL_HOST) === request()->getHost() ? $back : '/';
@endphp
@extends('errors.layout')
@section('title', 'Page expired')
@section('code', '419')
@section('title_ar', 'انتهت صلاحية الصفحة')
@section('message_ar', 'انتهت صلاحية الصفحة لأنها فضلت مفتوحة فترة طويلة. حدّث الصفحة وجرّب تاني.')
@section('message_en', 'This page expired because it stayed open for a long time. Refresh the page and try again.')
@section('action', '1')
@section('action_href', $back)
@section('action_label_ar', 'حدّث الصفحة')
@section('action_label_en', 'Refresh the page')
