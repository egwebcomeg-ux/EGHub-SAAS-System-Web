{{-- Which channel a conversation came from, by its logo. $channel: PlatformConversation::channel; the name stays for screen readers. --}}
@php($label = match ($channel) { \App\Models\PlatformConversation::MESSENGER => __('Messenger'), \App\Models\PlatformConversation::INSTAGRAM => __('Instagram'), default => __('WhatsApp') })
<span class="inline-flex shrink-0 align-[-0.2em]" title="{{ $label }}" data-channel-logo="{{ $channel }}">
    <span class="sr-only">{{ $label }}</span>
    @switch($channel)
        @case(\App\Models\PlatformConversation::MESSENGER)
            <svg class="size-4 text-[#0084FF]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M.001 11.639C.001 4.949 5.241 0 12.001 0S24 4.95 24 11.639c0 6.689-5.24 11.638-12 11.638-1.21 0-2.38-.16-3.47-.46a.96.96 0 00-.64.05l-2.39 1.05a.96.96 0 01-1.35-.85l-.07-2.14a.97.97 0 00-.32-.68A11.389 11.389 0 01.002 11.64zm8.32-2.19l-3.52 5.6c-.35.53.32 1.139.82.75l3.79-2.87c.25-.2.6-.2.86 0l2.8 2.1c.84.63 2.04.4 2.6-.48l3.52-5.6c.35-.53-.32-1.13-.82-.75l-3.79 2.87c-.25.2-.6.2-.86 0l-2.8-2.1a1.8 1.8 0 00-2.6.48z"/></svg>
            @break
        @case(\App\Models\PlatformConversation::INSTAGRAM)
            <svg class="size-4 text-[#E1306C]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="6"/><circle cx="12" cy="12" r="4.5"/><circle cx="17.5" cy="6.5" r="1.3" fill="currentColor" stroke="none"/></svg>
            @break
        @default
            <svg class="size-4 text-[#25D366]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.7.3-.2.3-.9.9-.9 2.2s1 2.6 1.1 2.7c.1.2 1.9 2.9 4.6 4 1.7.7 2.4.8 3.2.7.5-.1 1.5-.6 1.8-1.2.2-.6.2-1.1.1-1.2l-.4-.3Z"/></svg>
    @endswitch
</span>
