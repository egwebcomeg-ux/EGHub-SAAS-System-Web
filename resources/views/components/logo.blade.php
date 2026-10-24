@props(['size' => 32, 'text' => true, 'light' => false])
{{--
    EgHub logo: the "eghub" wordmark in the EgWeb logo's typeface (Diamonds, Font Bundles: commercial licence held by
    EgWeb) and colours: "eg" orange, "hub" EgWeb navy (white on dark backgrounds). Vector paths, so it is sharp at any
    size and ships no font file. :size is the visual height in px of the earlier mark, kept for callers.
    :text="false" draws "eg" only.
    A logo uploaded in admin › الهوية والشكل (App\Support\PlatformBrand) replaces the wordmark: the light-background
    one, or on dark backgrounds (:light) the dark-background one when there is one. "eg" follows the platform's
    primary colour.
--}}
@php
    $egColor = \App\Support\PlatformBrand::get('brand_color_primary') ?: '#F26522';
    $hubColor = $light ? '#ffffff' : '#213368';
    $height = round($size * 0.95);
    $wordmarkHeight = round($height * 1.25); // the typeface's x-height is short: same letter size as the earlier mark
    $uploadedLogo = $text ? (($light ? \App\Support\PlatformBrand::url('brand_logo_on_dark') : null) ?? \App\Support\PlatformBrand::url('brand_logo')) : null;
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center leading-none']) }} dir="ltr">
    @if ($uploadedLogo)
        <img src="{{ $uploadedLogo }}" alt="{{ \App\Support\PlatformBrand::siteName() }}" style="height: {{ $height }}px" class="block w-auto max-w-[12rem] shrink-0 object-contain">
    @elseif ($text)
        <svg height="{{ $wordmarkHeight }}" viewBox="0 -720 2660 940" class="block w-auto shrink-0" role="img" aria-label="EgHub">
            <g transform="scale(1 -1)"><g fill="{{ $egColor }}"><path transform="translate(0 0)" d="M522 249Q522 226 520 214H94Q107 152 156.5 110.5Q206 69 271 69Q309 69 343.0 83.5Q377 98 402 125L461 85Q425 44 375.5 21.0Q326 -2 271 -2Q203 -2 145.5 32.0Q88 66 54.0 123.5Q20 181 20 249Q20 317 54.0 375.0Q88 433 145.5 466.5Q203 500 271 500Q340 500 397.5 466.0Q455 432 488.5 374.0Q522 316 522 249ZM95 285H448Q435 347 386.0 388.0Q337 429 271 429Q206 429 156.5 388.5Q107 348 95 285Z"/><path transform="translate(552 0)" d="M521 250V51Q521 -17 487.5 -74.5Q454 -132 396.5 -165.5Q339 -199 271 -199H227H138V-129H271Q331 -128 379.0 -92.0Q427 -56 444 0H271Q203 0 145.5 33.5Q88 67 54.0 124.5Q20 182 20 250Q20 318 54.0 375.5Q88 433 145.5 466.5Q203 500 271 500Q339 500 396.5 466.5Q454 433 487.5 375.5Q521 318 521 250ZM271 429Q222 429 181.0 405.0Q140 381 115.5 340.0Q91 299 91 250Q91 201 115.5 160.0Q140 119 181.0 94.5Q222 70 271 70H450V250Q450 299 426.0 340.0Q402 381 361.0 405.0Q320 429 271 429Z"/></g><g fill="{{ $hubColor }}"><path transform="translate(1103 0)" d="M516 224 518 0H447V258Q446 305 421.5 344.5Q397 384 356.5 407.0Q316 430 269 430Q221 430 181.0 406.5Q141 383 116.5 343.0Q92 303 91 256V0H20V700H91V426Q125 461 171.0 480.5Q217 500 269 500Q336 500 393.5 466.5Q451 433 484.5 376.0Q518 319 518 252Q518 242 516 224Z"/><path transform="translate(1651 0)" d="M20 191V500H91Q91 296 92 194Q95 142 133.0 106.5Q171 71 223 71Q275 71 312.5 106.5Q350 142 354 194V500H425V191Q422 139 394.5 95.0Q367 51 321.5 25.5Q276 0 223 0Q170 0 125.0 25.5Q80 51 51.5 94.5Q23 138 20 191Z"/><path transform="translate(2106 0)" d="M520 250Q520 182 486.5 124.5Q453 67 395.5 33.5Q338 0 270 0Q202 0 144.5 33.5Q87 67 53.5 124.5Q20 182 20 250V700H90V500H270Q338 500 395.5 466.5Q453 433 486.5 375.5Q520 318 520 250ZM449 250Q449 299 425.0 340.0Q401 381 360.0 405.0Q319 429 270 429L90 430V250Q90 201 114.5 160.0Q139 119 180.0 95.0Q221 71 270 71Q319 71 360.0 95.0Q401 119 425.0 160.0Q449 201 449 250Z"/></g></g>
        </svg>
    @else
        <svg height="{{ $height }}" viewBox="0 -720 1060 940" class="block w-auto shrink-0" role="img" aria-label="EgHub">
            <g transform="scale(1 -1)" fill="{{ $egColor }}"><path transform="translate(0 0)" d="M522 249Q522 226 520 214H94Q107 152 156.5 110.5Q206 69 271 69Q309 69 343.0 83.5Q377 98 402 125L461 85Q425 44 375.5 21.0Q326 -2 271 -2Q203 -2 145.5 32.0Q88 66 54.0 123.5Q20 181 20 249Q20 317 54.0 375.0Q88 433 145.5 466.5Q203 500 271 500Q340 500 397.5 466.0Q455 432 488.5 374.0Q522 316 522 249ZM95 285H448Q435 347 386.0 388.0Q337 429 271 429Q206 429 156.5 388.5Q107 348 95 285Z"/><path transform="translate(552 0)" d="M521 250V51Q521 -17 487.5 -74.5Q454 -132 396.5 -165.5Q339 -199 271 -199H227H138V-129H271Q331 -128 379.0 -92.0Q427 -56 444 0H271Q203 0 145.5 33.5Q88 67 54.0 124.5Q20 182 20 250Q20 318 54.0 375.5Q88 433 145.5 466.5Q203 500 271 500Q339 500 396.5 466.5Q454 433 487.5 375.5Q521 318 521 250ZM271 429Q222 429 181.0 405.0Q140 381 115.5 340.0Q91 299 91 250Q91 201 115.5 160.0Q140 119 181.0 94.5Q222 70 271 70H450V250Q450 299 426.0 340.0Q402 381 361.0 405.0Q320 429 271 429Z"/></g>
        </svg>
    @endif
</span>
