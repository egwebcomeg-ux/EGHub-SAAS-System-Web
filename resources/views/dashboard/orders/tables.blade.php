@extends('layouts.dashboard', ['viteEntries' => ['resources/js/board.js']])
@section('title', __('Tables'))

@section('content')
@php
    $i18n = [
        'free' => __('Table free'), 'busy' => __('Occupied'), 'minutes' => __(':n min', ['n' => '__N__']),
        'orders' => __(':n orders', ['n' => '__N__']), 'unpaid' => __(':n unpaid', ['n' => '__N__']),
        'inKitchen' => __(':n in the kitchen', ['n' => '__N__']), 'readyCount' => __(':n ready', ['n' => '__N__']),
        'calls' => __('Waiter call'), 'bill' => __('Bill request'), 'currency' => __('EGP'),
        'closeTable' => __('Close table'), 'table' => __('Table :name', ['name' => '__N__']), 'since' => __('Since :time', ['time' => '__T__']),
        'subtotal' => __('Subtotal'), 'discount' => __('Discount'), 'service' => __('Service'), 'tax' => __('Tax'), 'total' => __('Total'),
        'paid' => __('Paid'), 'due' => __('Due'), 'notPaid' => __('Unpaid'), 'print' => __('Print bill'),
        'payAll' => __('Take :amount and close', ['amount' => '__A__']), 'closeOnly' => __('Close table'),
        'payOnly' => __('Take payment only'),
        'stillKitchen' => __('Some orders of this table are still being prepared.'),
        'closed' => __('Table closed. It is free now.'), 'empty' => __('No tables yet. Add them in Tables & QR.'),
        'method' => __('Payment method'), 'sure' => __('Close this table?'),
    ];
    $methodLabels = collect($methods)->mapWithKeys(fn ($m) => [$m => \App\Billing\PaymentMethods::label($m)]);
@endphp
<div class="dash-head">
    <div class="min-w-0">
        <h1 class="page-title">{{ __('Tables') }}</h1>
        <p class="dash-help">{{ __('Every order of a table joins one bill until you close the table.') }}</p>
    </div>
    <div class="dash-head-actions gap-4 text-sm font-semibold">
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-emerald-500"></span> {{ __('Table free') }} <b data-free-count>0</b></span>
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-brand-600"></span> {{ __('Occupied') }} <b data-busy-count>0</b></span>
    </div>
</div>
@include('dashboard.orders.partials.tabs')

<div id="tables-grid" class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6"
     data-feed="{{ route('dash.orders.tables.feed') }}"
     data-bill-url="{{ url('/orders/tables/sessions/__ID__') }}"
     data-pay-url="{{ url('/orders/tables/sessions/__ID__/pay') }}"
     data-close-url="{{ url('/orders/tables/sessions/__ID__/close') }}"
     data-poll="{{ $poll }}"
     data-can-pay="{{ $canPay ? 1 : 0 }}"
     data-methods='{{ json_encode($methodLabels, JSON_UNESCAPED_UNICODE) }}'
     data-i18n='{{ json_encode($i18n, JSON_UNESCAPED_UNICODE) }}'></div>

{{-- the combined bill of a table: every order, the totals, one payment and "close table" --}}
<dialog class="m-auto w-[min(100%-2rem,32rem)] rounded-2xl p-0 shadow-2xl backdrop:bg-navy-900/50" data-bill-dialog>
    <div class="max-h-[85dvh] overflow-y-auto p-5" data-bill-body></div>
</dialog>
@endsection
