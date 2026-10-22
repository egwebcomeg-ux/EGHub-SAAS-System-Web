{{-- Leaflet map library (OpenStreetMap tiles), pinned version from the CDN (App\Support\Leaflet); loaded only on pages with a map. --}}
@once
    @push('head')
        <link rel="stylesheet" href="{{ \App\Support\Leaflet::CSS }}" integrity="{{ \App\Support\Leaflet::CSS_INTEGRITY }}" crossorigin="">
        <script src="{{ \App\Support\Leaflet::JS }}" integrity="{{ \App\Support\Leaflet::JS_INTEGRITY }}" crossorigin=""></script>
    @endpush
@endonce
