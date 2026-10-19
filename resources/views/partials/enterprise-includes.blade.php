{{-- What the Enterprise (contact sales) plan adds, the same list on the pricing page, the billing page and the module picker. --}}
@php
    $enterpriseLines = [
        __('POS cashier, shifts and a customer display'),
        __('Kitchen and bar screens, a screen per station'),
        __('Self-order kiosk'),
        __('Call-center screen for phone orders'),
        __('Inventory and recipe costing'),
        __('More than 5 branches, with prices per branch'),
        __('Unlimited users and AI menu imports'),
        __('Setup with you and training for your team'),
    ];
@endphp
<ul class="{{ $listClass ?? 'space-y-2 text-sm' }}" data-enterprise-includes>
    @foreach ($enterpriseLines as $line)
        <li class="flex items-start gap-2">
            <svg class="mt-1 size-4 shrink-0 {{ $iconClass ?? 'text-brand-600' }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0z"/></svg>
            <span>{{ $line }}</span>
        </li>
    @endforeach
</ul>
