{{-- Orders board tabs: the live orders, the tables (sessions, combined bill, close table) and the delivery drivers. --}}
@if (auth()->user()->can('orders.manage'))
    @php
        $orderTabs = [['dash.orders', '🧾 '.__('Orders')], ['dash.orders.tables', '🪑 '.__('Tables')]];
        if (auth()->user()->canAny(['delivery.assign', 'delivery.drivers']) && \App\Services\Entitlements::current()->allows('delivery_zones|online_ordering')) {
            $orderTabs[] = ['dash.drivers', '🛵 '.__('Drivers')];
        }
    @endphp
    <nav class="mb-4 inline-flex max-w-full rounded-xl bg-white p-1 shadow-sm ring-1 ring-navy-100" aria-label="{{ __('Orders') }}">
        @foreach ($orderTabs as [$route, $label])
            <a href="{{ route($route) }}" @class(['flex min-h-10 items-center rounded-lg px-4 text-sm font-bold transition',
                'bg-navy-800 text-white' => request()->routeIs($route), 'text-navy-700 hover:bg-navy-50' => ! request()->routeIs($route)])
                @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
@endif
