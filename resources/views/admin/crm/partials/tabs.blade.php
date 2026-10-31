{{-- the sales pages, one row of tabs: sign-ups follow-up, places, visits, import, team, the printed plan --}}
@php
    $me = auth('admin')->user();
    $tabs = array_filter([
        $me->allows('crm.manage') ? ['admin.crm', __('Sign-ups'), ['admin.crm', 'admin.crm.show']] : null,
        ['admin.crm.prospects', __('Places'), ['admin.crm.prospects*']],
        $me->doesFieldVisits() ? ['admin.crm.visits', __('My visits'), ['admin.crm.visits*']] : null,
        $me->allows('crm.import') ? ['admin.crm.import', __('Import places'), ['admin.crm.import*']] : null,
        $me->allows('crm.team') ? ['admin.crm.team', __('Sales team'), ['admin.crm.team*']] : null,
        ['admin.crm.playbook', __('Team plan'), ['admin.crm.playbook']],
    ]);
@endphp
<nav class="-mx-1 mb-4 flex gap-1 overflow-x-auto pb-1 print:hidden" aria-label="{{ __('Sales pages') }}" data-crm-tabs>
    @foreach ($tabs as [$route, $label, $patterns])
        @php($on = request()->routeIs(...$patterns))
        <a href="{{ route($route) }}" @if ($on) aria-current="page" @endif @class(['shrink-0 rounded-full px-4 py-2 text-sm font-bold transition',
            'bg-navy-900 text-white' => $on, 'bg-white text-navy-700 ring-1 ring-navy-100 hover:bg-navy-50' => ! $on])>{{ $label }}</a>
    @endforeach
</nav>
