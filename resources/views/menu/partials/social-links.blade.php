{{--
    The restaurant's social accounts (settings instagram, facebook, tiktok); nothing when none is set.
    $style 'header': small round icons next to the name in the menu header (white on the header colour).
    $style 'footer': a "follow us" card with big labelled buttons, in the menu's own colours.
--}}
@php
    $socialIcons = [
        'instagram' => ['Instagram', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>'],
        'facebook' => ['Facebook', '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 21v-7.5h2.6l.4-3h-3V8.6c0-.9.3-1.5 1.5-1.5h1.6V4.4c-.3 0-1.2-.1-2.3-.1-2.3 0-3.9 1.4-3.9 4v2.2H7.8v3h2.6V21h3.1z"/></svg>'],
        'tiktok' => ['TikTok', '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.6 3c.3 2.1 1.5 3.6 3.6 3.8v3c-1.4.1-2.6-.3-3.6-1v6.3c0 3.3-2.5 5.9-5.8 5.9S5 18.4 5 15.2c0-3.4 2.9-6 6.4-5.7v3.1c-1.6-.3-3.2.9-3.2 2.6 0 1.4 1.2 2.6 2.6 2.6 1.6 0 2.7-1.2 2.7-2.8V3h3.1z"/></svg>'],
    ];
    $socialLinks = collect($socialIcons)->filter(fn ($icon, $key) => filled($settings[$key] ?? null));
@endphp
@if ($socialLinks->isNotEmpty())
    @if (($style ?? 'footer') === 'header')
        <span class="inline-flex items-center gap-1.5 align-middle" data-social="header">
            @foreach ($socialLinks as $key => [$label, $svg])
                <a href="{{ $settings[$key] }}" target="_blank" rel="noopener" aria-label="{{ $label }}" title="{{ $label }}"
                   class="inline-grid size-8 place-items-center rounded-full bg-white/15 text-white hover:bg-white/25 [&>svg]:size-4">{!! $svg !!}</a>
            @endforeach
        </span>
    @else
        <section class="m-card mx-auto max-w-md rounded-2xl p-4 text-center" data-social="footer">
            <h2 class="text-base font-black" style="color: var(--menu-text)">{{ __('Follow us') }}</h2>
            <div class="mt-3 flex flex-wrap justify-center gap-2">
                @foreach ($socialLinks as $key => [$label, $svg])
                    <a href="{{ $settings[$key] }}" target="_blank" rel="noopener"
                       class="inline-flex min-h-11 items-center gap-2 rounded-full px-4 font-bold [&>svg]:size-5"
                       style="background: var(--menu-accent); color: var(--menu-accent-text)">{!! $svg !!}<span>{{ $label }}</span></a>
                @endforeach
            </div>
        </section>
    @endif
@endif
