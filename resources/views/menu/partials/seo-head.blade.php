{{-- Search and link-preview tags of a public restaurant page: $profile (RestaurantProfile), $schemas (JSON-LD arrays). --}}
<meta name="description" content="{{ $profile->description() }}">
<meta property="og:type" content="restaurant.restaurant">
<meta property="og:site_name" content="EgMenu">
<meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_EG' : 'en_US' }}">
<meta property="og:title" content="{{ $profile->title() }}">
<meta property="og:description" content="{{ $profile->description() }}">
@if (request()->attributes->get('canonical'))
    <meta property="og:url" content="{{ request()->attributes->get('canonical') }}">
@endif
<meta property="og:image" content="{{ $profile->shareImage() }}">
<meta name="twitter:card" content="summary_large_image">
@foreach ($schemas as $schema)
    <script type="application/ld+json">{!! \App\Services\Seo\RestaurantProfile::json($schema) !!}</script>
@endforeach
