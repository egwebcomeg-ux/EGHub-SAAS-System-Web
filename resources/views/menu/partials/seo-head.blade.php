{{--
    Search and link-preview tags of a public restaurant page: $profile (RestaurantProfile), $schemas (JSON-LD arrays)
    and optionally $share = [title, description, image] of one item (its shareable link /item/{id}).
--}}
@php
    $shareTitle = $share['title'] ?? $profile->title();
    $shareDescription = ($share['description'] ?? '') ?: $profile->description();
    $shareImage = ($share['image'] ?? null) ?: $profile->shareImage();
@endphp
<meta name="description" content="{{ $shareDescription }}">
<meta property="og:type" content="{{ isset($share) ? 'product' : 'restaurant.restaurant' }}">
<meta property="og:site_name" content="EgHub">
<meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_EG' : 'en_US' }}">
<meta property="og:title" content="{{ $shareTitle }}">
<meta property="og:description" content="{{ $shareDescription }}">
@if (request()->attributes->get('canonical'))
    <meta property="og:url" content="{{ request()->attributes->get('canonical') }}">
@endif
<meta property="og:image" content="{{ $shareImage }}">
<meta name="twitter:card" content="summary_large_image">
@foreach ($schemas as $schema)
    <script type="application/ld+json">{!! \App\Services\Seo\RestaurantProfile::json($schema) !!}</script>
@endforeach
