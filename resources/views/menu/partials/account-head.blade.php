{{-- the top of the small private account pages (/me/email, /me/reset, /me/claim): the logo, the place, a title --}}
<header class="mb-5 flex items-center gap-3">
    @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-12 shrink-0 rounded-2xl bg-white object-contain p-1 shadow-sm">@endif
    <div class="min-w-0">
        <div class="truncate text-sm font-bold m-muted">{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</div>
        <h1 class="text-2xl font-black">{{ $title }}</h1>
    </div>
</header>
