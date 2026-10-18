{{-- The two tabs of "Plans & add-ons" (admin.plans and admin.addons). --}}
<div class="mb-4">
    <h1 class="page-title">{{ __('Plans & add-ons') }}</h1>
    <p class="muted">{{ __('Prices, features and limits of every plan, and what is sold on top. Changes apply to all restaurants on it right away.') }}</p>
    <nav class="mt-3 flex gap-1 border-b border-navy-100" aria-label="{{ __('Plans & add-ons') }}">
        @foreach ([['admin.plans', __('Plans'), 'admin.plans*'], ['admin.addons', __('Add-ons'), 'admin.addons*']] as [$route, $label, $pattern])
            <a href="{{ route($route) }}" @class(['-mb-px inline-flex min-h-11 items-center gap-2 border-b-2 px-4 text-sm font-bold transition',
                'border-brand-600 text-navy-900' => request()->routeIs($pattern), 'border-transparent text-navy-500 hover:text-navy-800' => ! request()->routeIs($pattern)])
                @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
</div>
