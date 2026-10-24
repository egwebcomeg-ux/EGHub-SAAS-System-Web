{{--
    The share image of a marketing page (Open Graph and Twitter), in the page's language.
    @section('share_card', 'features/pager') picks the page's own card (App\Support\ShareCards, public/og/{ar|en}/…png,
    1200×630); a page with an image of its own (a blog post's cover) sets @section('og_image', $url) instead, whose size
    is unknown. Without either, or when the card file is missing, the site-wide og-image.png is used.
--}}
@php
    $shareTitle = trim($__env->yieldContent('title', 'EgHub'));
    if ($__env->hasSection('og_image')) {
        $shareImage = trim($__env->yieldContent('og_image'));
        $shareSize = null;
        $shareAlt = $shareTitle;
    } else {
        $shareKey = $__env->hasSection('share_card') ? trim($__env->yieldContent('share_card')) : null;
        $shareImage = \App\Support\ShareCards::url($shareKey);
        $shareSize = [\App\Support\ShareCards::WIDTH, \App\Support\ShareCards::HEIGHT];
        $shareAlt = \App\Support\ShareCards::alt($shareKey, $shareTitle);
    }
@endphp
<meta property="og:image" content="{{ $shareImage }}">
@if ($shareSize)
<meta property="og:image:width" content="{{ $shareSize[0] }}">
<meta property="og:image:height" content="{{ $shareSize[1] }}">
<meta property="og:image:type" content="image/png">
@endif
<meta property="og:image:alt" content="{{ $shareAlt }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="{{ $shareImage }}">
<meta name="twitter:image:alt" content="{{ $shareAlt }}">
