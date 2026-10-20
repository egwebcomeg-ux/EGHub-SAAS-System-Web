{{-- The POS area's tabs: cashier screen, shifts, terminals (owner / manager), end of day report. $current = open key. --}}
@php
    $u = auth()->user();
    $tabs = [];
    if ($u->can('pos.use')) {
        $tabs[] = ['pos', __('Cashier screen'), route('dash.pos')];
    }
    if ($u->can('shifts.manage') && \App\Services\Entitlements::current()->allows('shifts')) {
        $tabs[] = ['shifts', __('Shifts'), route('dash.shifts')];
    }
    if ($u->can('branches.manage')) {
        $tabs[] = ['terminals', __('Terminals'), route('dash.terminals')];
    }
    if (\Illuminate\Support\Facades\Route::has('dash.zreport') && ($u->can('reports.view') || $u->can('shifts.manage'))) {
        $tabs[] = ['zreport', __('End of day (Z)'), route('dash.zreport')];
    }
@endphp
@if (count($tabs) > 1)
    @include('dashboard.partials.tabs', ['label' => __('POS'), 'current' => $current, 'tabs' => $tabs])
@endif
