@extends('layouts.dashboard')
@section('title', __('Users & roles'))

@section('content')
<div class="mb-4 flex items-center justify-between">
    <h1 class="text-2xl font-extrabold">{{ __('Users & roles') }}</h1>
</div>

<div class="grid gap-4 xl:grid-cols-3">
    <div class="card overflow-x-auto xl:col-span-2">
        <table class="table-base">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Login') }}</th><th>{{ __('Role') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($users as $u)
                <tr>
                    <td class="font-semibold">{{ $u->name }}</td>
                    <td dir="ltr" class="text-start text-navy-500">{{ $u->email ?? $u->phone }}</td>
                    <td>{{ $u->roleName() }}</td>
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
    </div>
</div>
@endsection
