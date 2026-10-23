@extends('layouts.dashboard')
@section('title', __('Users & roles'))

@section('content')
<x-dash.page-header :title="__('Users & roles')" :help="__('Add your team and choose what each one can see and do.')" />

<div class="grid gap-4 xl:grid-cols-3" data-users-page data-role-info='@json($roleInfo)'>
    <div class="card overflow-x-auto xl:col-span-2">
        <table class="table-base">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Login') }}</th><th>{{ __('Role') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($users as $u)
                <tr>
                    <td class="font-semibold">{{ $u->name }}</td>
                    <td dir="ltr" class="text-start text-navy-500">{{ $u->email ?? $u->phone }}</td>
                    <td>
                        {{ $u->roleName() }}
                        @if ($canCustomize && $u->permissions->isNotEmpty())
                            <span class="badge bg-brand-50 text-brand-700" title="{{ $u->permissions->map(fn ($p) => \App\Support\Permissions::label($p->name))->join(' · ') }}" data-extra-badge>+{{ $u->permissions->count() }} {{ __('extra') }}</span>
                        @endif
                    </td>
                    <td>{{ $u->branch ? tr($u->branch->name, $u->branch->name_en) : __('All branches') }}</td>
                    <td>
                        <span class="badge {{ $u->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-navy-100 text-navy-500' }}">{{ $u->is_active ? __('Active') : __('Disabled') }}</span>
                    </td>
                    <td>
                        <details class="relative">
                            <summary class="btn-ghost min-h-9 cursor-pointer list-none px-3">{{ __('Edit') }}</summary>
                            <form method="post" action="{{ route('dash.users.update', $u) }}" class="card absolute end-0 z-10 mt-2 w-80 space-y-3">
                                @csrf
                                @include('dashboard.partials.user-fields', ['u' => $u])
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($u->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                                <button class="btn-primary w-full">{{ __('Save') }}</button>
                            </form>
                        </details>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 class="mb-3 text-lg font-bold">{{ __('Add user') }}</h2>
        @if ($canAdd)
            <form method="post" action="{{ route('dash.users.store') }}" class="space-y-3">
                @csrf
                @include('dashboard.partials.user-fields', ['u' => null])
                <button class="btn-primary w-full">{{ __('Add user') }}</button>
            </form>
        @else
            <p class="text-sm text-navy-500">{{ \App\Services\Entitlements::current()->upgradeMessage('staff') }}</p>
        @endif
        @unless ($canCustomize)
            <p class="mt-3 text-xs text-navy-500" data-permissions-locked>{{ __('Custom permissions per staff member (on top of their role) come with the Complete plan.') }}</p>
        @endunless
    </div>
</div>

<script>
    (() => {
        const page = document.querySelector('[data-users-page]');
        const info = JSON.parse(page.dataset.roleInfo);
        // each form: the role's hint and permissions under the select; the role's permissions ticked and locked in
        // the extra list, the rest free (extra permissions); the owner role hides the list (it has everything).
        // A box is ticked by the role or by the member's own extras (data-own): switching the role recomputes the
        // role boxes, so the previous role's boxes never stay ticked and get saved as extras.
        page.querySelectorAll('form').forEach((form) => {
            const select = form.querySelector('[data-role]');
            if (!select) return;
            const extra = form.querySelector('[data-extra-permissions]');
            const roleField = form.querySelector('[data-permissions-role]');
            extra?.querySelectorAll('[data-permission]').forEach((box) => { box.dataset.own = box.checked ? '1' : '0'; });
            const sync = () => {
                const role = info[select.value] || { hint: '', permissions: [], labels: [] };
                form.querySelector('[data-role-hint]').textContent = role.hint;
                form.querySelector('[data-role-permissions]').textContent = role.labels.join(' · ');
                if (roleField) { roleField.value = select.value; }
                if (!extra) return;
                extra.classList.toggle('hidden', select.value === 'owner');
                let count = 0;
                extra.querySelectorAll('[data-permission]').forEach((box) => {
                    const inRole = role.permissions.includes(box.dataset.permission);
                    box.checked = inRole || box.dataset.own === '1';
                    box.disabled = inRole || box.dataset.grantable !== '1';
                    if (!inRole && box.checked) { count++; }
                });
                extra.querySelector('[data-extra-count]').textContent = count ? `(${count})` : '';
            };
            select.addEventListener('change', sync);
            extra?.addEventListener('change', (event) => {
                const box = event.target.closest('[data-permission]');
                if (box && !box.disabled) { box.dataset.own = box.checked ? '1' : '0'; }
                sync();
            });
            sync();
        });
    })();
</script>
@endsection
