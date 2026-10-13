@props(['size' => 32, 'text' => true, 'light' => false])
{{-- EgMenu mark: three QR finder squares + an orange square with fork and knife (logo direction B). --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 48 48" aria-hidden="true">
        @php($ink = $light ? '#ffffff' : '#1E2D4F')
        <rect x="2" y="2" width="20" height="20" rx="5" fill="none" stroke="{{ $ink }}" stroke-width="4"/>
        <rect x="9" y="9" width="6" height="6" rx="1.5" fill="{{ $ink }}"/>
        <rect x="26" y="2" width="20" height="20" rx="5" fill="none" stroke="{{ $ink }}" stroke-width="4"/>
        <rect x="33" y="9" width="6" height="6" rx="1.5" fill="{{ $ink }}"/>
        <rect x="2" y="26" width="20" height="20" rx="5" fill="none" stroke="{{ $ink }}" stroke-width="4"/>
        <rect x="9" y="33" width="6" height="6" rx="1.5" fill="{{ $ink }}"/>
        <rect x="26" y="26" width="20" height="20" rx="5" fill="#E8642C"/>
        <path d="M32 30v5a2 2 0 0 0 2 2v5M34 30v4M36 30v5a2 2 0 0 1-2 2" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round"/>
        <path d="M41 30c-1.6 0-2.4 2.2-2.4 5h2.4v7" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    @if ($text)
        <span dir="ltr" class="text-xl font-extrabold tracking-tight {{ $light ? 'text-white' : 'text-navy-800' }}"><span class="text-brand-600">eg</span>menu</span>
    @endif
</span>
