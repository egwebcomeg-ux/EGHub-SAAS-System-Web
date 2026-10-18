{{-- Orders board tabs: the live orders and the tables (sessions, combined bill, close table). --}}
@if (auth()->user()->can('orders.manage'))
    <nav class="mb-4 inline-flex rounded-xl bg-white p-1 shadow-sm ring-1 ring-navy-100" aria-label="{{ __('Orders') }}">
        @foreach ([['dash.orders', '🧾 '.__('Orders')], ['dash.orders.tables', '🪑 '.__('Tables')]] as [$route, $label])
            <a href="{{ route($route) }}" @class(['min-h-10 rounded-lg px-4 py-2 text-sm font-bold transition',
                'bg-navy-800 text-white' => request()->routeIs($route), 'text-navy-700 hover:bg-navy-50' => ! request()->routeIs($route)])
                @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
@endif
