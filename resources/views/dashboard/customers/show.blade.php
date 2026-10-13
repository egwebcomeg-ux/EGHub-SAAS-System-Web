@extends('layouts.dashboard')
@section('title', $customer->name ?: $customer->phone)

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ $customer->name ?: __('Guest') }}</h1>
        <div class="muted" dir="ltr">{{ $customer->phone }}</div>
    </div>
    <div class="flex gap-2">
        <a href="https://wa.me/{{ whatsapp_number($customer->phone) }}" target="_blank" rel="noopener" class="btn-ghost">WhatsApp</a>
        <a href="{{ route('dash.customers') }}" class="btn-ghost">{{ __('Back') }}</a>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4">
        <div class="card grid grid-cols-2 gap-3">
            <div><div class="muted">{{ __('Visits') }}</div><div class="text-2xl font-extrabold">{{ $customer->visits_count }}</div></div>
            <div><div class="muted">{{ __('Total spent') }}</div><div class="text-2xl font-extrabold">{{ money($customer->total_spent) }}</div></div>
            <div class="col-span-2 muted">{{ __('Last visit') }}: {{ local_time($customer->last_visit_at, 'Y/m/d') }}</div>
        </div>
        @foreach ($customer->loyaltyCards as $card)
            <div class="card">
                <div class="muted">{{ tr($card->program->title, $card->program->title_en) }}</div>
                <div class="text-xl font-extrabold">{{ $card->stamps }} @unless ($card->program->type === 'points')/ {{ $card->program->stamps_required }}@endunless</div>
                @foreach ($card->availableRewards as $r)<div class="mt-1 text-sm font-semibold text-emerald-700">🎁 {{ $r->label }}</div>@endforeach
                @can('loyalty.stamp')<a href="{{ route('dash.loyalty.desk', ['q' => $customer->phone]) }}" class="btn-ghost mt-3 w-full">{{ __('Open at the counter') }}</a>@endcan
            </div>
        @endforeach
        <form method="post" action="{{ route('dash.customers.update', $customer) }}" class="card space-y-3">
            @csrf
            <input class="field" name="name" value="{{ $customer->name }}" placeholder="{{ __('Name') }}" maxlength="100">
            <input class="field" name="phone_2" value="{{ $customer->phone_2 }}" placeholder="{{ __('Second mobile') }}" dir="ltr">
            <label class="field-label">{{ __('Birthday') }}</label>
            <input class="field" type="date" name="birthday" value="{{ $customer->birthday?->format('Y-m-d') }}">
            <textarea class="field min-h-20 py-2" name="notes" placeholder="{{ __('Notes, e.g. favorite table') }}" maxlength="1000">{{ $customer->notes }}</textarea>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="marketing_opt_in" value="1" @checked($customer->marketing_opt_in) class="size-4 accent-brand-600"> {{ __('Accepts offers') }}</label>
            <button class="btn-primary w-full">{{ __('Save') }}</button>
        </form>
    </div>
    <section class="card overflow-x-auto lg:col-span-2">
        <h2 class="section-title mb-3">{{ __('Orders') }}</h2>
        <table class="table-base">
            <tbody>
            @forelse ($orders as $o)
                <tr><td class="font-semibold">#{{ $o->number }}</td><td>{{ local_time($o->created_at, 'Y/m/d h:i A') }}</td><td>{{ $o->statusLabel() }}</td><td class="text-end font-bold">{{ money($o->total) }}</td></tr>
            @empty
                <tr><td class="py-6 text-center text-navy-500">{{ __('No orders yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
