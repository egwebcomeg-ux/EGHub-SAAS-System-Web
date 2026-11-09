@extends('layouts.dashboard')
@section('title', __('Terminals'))

@section('content')
<x-dash.page-header :title="__('POS')" :help="__('Name the tills of each branch. Each device picks its terminal once; its sales, shifts and offline numbers carry the terminal code.')">
    <x-slot:actions>
        @can('pos.use')<a href="{{ route('dash.pos') }}" class="btn-primary">{{ __('Go to POS') }}</a>@endcan
    </x-slot:actions>
</x-dash.page-header>
@include('dashboard.pos.tabs', ['current' => 'terminals'])

<div class="grid gap-4 lg:grid-cols-3">
    <section class="card overflow-x-auto lg:col-span-2" data-terminals>
        <h2 class="section-title mb-3">{{ __('Terminals') }}</h2>
        @if ($terminals->isEmpty())
            <p class="muted">{{ __('No terminals yet. Add one per cash desk, e.g. "Cashier 1".') }}</p>
        @else
            <table class="table-base">
                <thead><tr><th>{{ __('Terminal') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Device') }}</th><th>{{ __('Open shift') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($terminals as $t)
                    <tr data-terminal="{{ $t->code }}">
                        <td>
                            <form method="post" action="{{ route('dash.terminals.update', $t) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <span class="badge bg-navy-800 text-white" dir="ltr">{{ $t->code }}</span>
                                <input class="field min-h-10 w-36" name="name" value="{{ $t->name }}" maxlength="60" required aria-label="{{ __('Name') }}">
                                <label class="flex min-h-10 items-center gap-1 text-sm"><input type="checkbox" name="is_active" value="1" @checked($t->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                                <button class="btn-ghost min-h-10 px-3 text-sm">{{ __('Save') }}</button>
                            </form>
                        </td>
                        <td>{{ tr($t->branch?->name, $t->branch?->name_en) }}</td>
                        <td class="text-sm">
                            @if ($t->isClaimed())
                                <div class="font-semibold">{{ $t->claimer?->name }}</div>
                                <div class="muted">{{ $t->device_label }} · {{ __('Last seen :time', ['time' => local_time($t->last_seen_at, 'm/d h:i A')]) }}</div>
                            @else
                                <span class="muted">{{ __('No device yet') }}</span>
                            @endif
                        </td>
                        <td class="text-sm">
                            @if ($s = $open->get($t->id))
                                <span class="badge bg-emerald-100 text-emerald-800">{{ $s->user?->name }} · {{ local_time($s->opened_at, 'h:i A') }}</span>
                            @else
                                <span class="muted">—</span>
                            @endif
                            <a class="ms-1 text-brand-700 underline" href="{{ route('dash.shifts', ['terminal' => $t->id]) }}">{{ __('Shifts') }}</a>
                        </td>
                        <td>
                            @if ($t->isClaimed())
                                <form method="post" action="{{ route('dash.terminals.release', $t) }}" onsubmit="return confirm(@js(__('Sign the device off this terminal?')))">
                                    @csrf<button class="min-h-10 px-2 text-sm text-red-600">{{ __('Free the terminal') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <div class="space-y-4">
        <form method="post" action="{{ route('dash.terminals.store') }}" class="card space-y-2">
            @csrf
            <h2 class="section-title">{{ __('Add a terminal') }}</h2>
            <select class="field" name="branch_id" required aria-label="{{ __('Branch') }}">
                @foreach ($branches as $b)<option value="{{ $b->id }}">{{ tr($b->name, $b->name_en) }}</option>@endforeach
            </select>
            <input class="field" name="name" required maxlength="60" placeholder="{{ __('e.g. Cashier 1') }}" value="{{ old('name') }}">
            <input class="field uppercase" name="code" maxlength="8" dir="ltr" placeholder="{{ __('Code (empty = K1, K2…)') }}" value="{{ old('code') }}">
            <button class="btn-primary w-full">{{ __('Add') }}</button>
        </form>
        <form method="post" action="{{ route('dash.terminals.settings') }}" class="card space-y-2">
            @csrf
            <h2 class="section-title">{{ __('Offline mode') }}</h2>
            <p class="muted text-sm">{{ __('When the internet drops, a terminal with an open shift keeps selling (cash, card, InstaPay, mobile wallet) and syncs when it is back. After this many hours without syncing it stops taking new offline sales.') }}</p>
            <label class="field-label" for="pos_offline_hours">{{ __('Hours allowed offline') }}</label>
            <input class="field" id="pos_offline_hours" name="pos_offline_hours" type="number" min="1" max="72" value="{{ $offlineHours }}" required>
            <h2 class="section-title pt-2">{{ __('Kitchen display') }}</h2>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="field-label" for="kitchen_warn_minutes">{{ __('Kitchen ticket turns amber after (minutes)') }}</label>
                    <input class="field" id="kitchen_warn_minutes" name="kitchen_warn_minutes" type="number" min="1" max="120" value="{{ $kitchenMinutes[0] }}" required>
                </div>
                <div>
                    <label class="field-label" for="kitchen_late_minutes">{{ __('and red after (minutes)') }}</label>
                    <input class="field" id="kitchen_late_minutes" name="kitchen_late_minutes" type="number" min="2" max="240" value="{{ $kitchenMinutes[1] }}" required>
                </div>
            </div>
            <button class="btn-ghost w-full">{{ __('Save') }}</button>
        </form>
        {{-- App\Services\Orders\WaiterCash: the waiter's cash stays with them until a cashier takes it on the shifts page --}}
        <form method="post" action="{{ route('dash.terminals.waiter') }}" class="card space-y-2" data-waiter-payment>
            @csrf
            <h2 class="section-title">{{ __('Waiters and payment') }}</h2>
            <label class="flex items-start gap-2 text-sm font-semibold"><input type="checkbox" class="mt-0.5 size-5" name="pos_waiter_pay_first" value="1" @checked($waiterPayFirst)> {{ __('The waiter takes the money before the order goes to the kitchen') }}</label>
            <p class="muted text-sm">{{ __('A waiter\'s order is sent only with its payment. The cash stays with the waiter until the cashier takes it on the shifts page; card, InstaPay and wallet go straight into the open shift. The cashier and the manager still open tables and pay at the end.') }}</p>
            <button class="btn-ghost w-full">{{ __('Save') }}</button>
        </form>
        {{-- Dashboard\PosController::requiresPhone: every cashier order is tied to a customer (found or saved with it) --}}
        <form method="post" action="{{ route('dash.terminals.phone') }}" class="card space-y-2" data-require-phone>
            @csrf
            <h2 class="section-title">{{ __('Customer details') }}</h2>
            <label class="flex items-start gap-2 text-sm font-semibold"><input type="checkbox" class="mt-0.5 size-5" name="pos_require_phone" value="1" @checked($requirePhone)> {{ __('No order from the cashier without the customer\'s mobile') }}</label>
            <p class="muted text-sm">{{ __('Takeaway and dine-in alike: the cashier types the mobile, a known customer comes up with their name, a new one is saved with the order. Delivery and call-center orders always ask for it.') }}</p>
            <button class="btn-ghost w-full">{{ __('Save') }}</button>
        </form>
        {{-- App\Services\Orders\OrderService::walletFee: the guest pays the mobile wallet's fee on top of the bill --}}
        <form method="post" action="{{ route('dash.terminals.wallet_fee') }}" class="card space-y-2" data-wallet-fee>
            @csrf
            <h2 class="section-title">{{ __('Mobile wallet fee') }}</h2>
            <div class="flex items-center gap-2">
                <input class="field w-28" id="wallet_fee_percent" name="wallet_fee_percent" type="number" min="0" max="10" step="0.1" value="{{ $walletFee }}" required>
                <label for="wallet_fee_percent" class="text-sm font-semibold">{{ __('% added when the guest pays by mobile wallet') }}</label>
            </div>
            <p class="muted text-sm">{{ __('At the cashier, the call center and the door. It shows in the payment window and on the receipt; 0 = no fee.') }}</p>
            <button class="btn-ghost w-full">{{ __('Save') }}</button>
        </form>
        {{-- printing from the EgHub cashier app (App\Services\Printing\PrintJobs); in a browser the receipt page prints as before --}}
        <form method="post" action="{{ route('dash.pos.printing') }}" class="card space-y-2" data-printing-settings>
            @csrf
            <h2 class="section-title">{{ __('Automatic printing') }}</h2>
            <p class="muted text-sm">{{ __('With the EgHub cashier app on the till, the receipt and the kitchen tickets print by themselves on the printers chosen in the app. Each copy prints once, even with several devices. In a browser nothing changes: the receipt opens to print.') }}</p>
            <label class="flex items-start gap-2 text-sm font-semibold"><input type="checkbox" class="mt-0.5 size-5" name="pos_print_receipt_auto" value="1" @checked($printing['receipt_auto'])> {{ __('Print the receipt by itself after the payment') }}</label>
            <label class="flex items-start gap-2 text-sm font-semibold"><input type="checkbox" class="mt-0.5 size-5" name="pos_print_kitchen_auto" value="1" @checked($printing['kitchen_auto'])> {{ __('Print a kitchen ticket for each station as soon as the order arrives') }}</label>
            <fieldset class="ms-7 rounded-xl border border-navy-100 p-3" data-kitchen-from>
                <legend class="px-1 text-sm font-semibold text-navy-700">{{ __('Only the orders from') }}</legend>
                @php($fromAll = count($printing['kitchen_from']) === count(\App\Services\Printing\PrintJobs::KITCHEN_FROM))
                <div class="grid gap-1 sm:grid-cols-2">
                    @foreach (\App\Services\Printing\PrintJobs::KITCHEN_FROM as $key => $label)
                        <label class="flex min-h-9 items-center gap-2 text-sm"><input type="checkbox" class="size-5" name="pos_print_kitchen_from[]" value="{{ $key }}" @checked($fromAll || in_array($key, $printing['kitchen_from'], true))> {{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}</label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-navy-500">{{ __('A waiter\'s order is one placed by a staff member with the Waiter role. Nothing ticked prints every order.') }}</p>
            </fieldset>
            <label class="flex items-start gap-2 text-sm font-semibold"><input type="checkbox" class="mt-0.5 size-5" name="pos_print_open_drawer" value="1" @checked($printing['open_drawer'])> {{ __('Open the cash drawer with a cash payment') }}</label>
            <label class="flex items-start gap-2 text-sm font-semibold"><input type="checkbox" class="mt-0.5 size-5" name="pos_print_logo" value="1" @checked($printing['logo'])> {{ __('The logo on top of the receipt') }}</label>
            <div class="grid grid-cols-2 gap-2">
                <label class="block"><span class="field-label">{{ __('Receipt copies') }}</span>
                    <input class="field" name="pos_print_copies" type="number" min="1" max="{{ \App\Services\Printing\PrintJobs::MAX_COPIES }}" value="{{ $printing['copies'] }}" required></label>
                <label class="block"><span class="field-label">{{ __('Kitchen ticket copies') }}</span>
                    <input class="field" name="pos_print_kitchen_copies" type="number" min="1" max="{{ \App\Services\Printing\PrintJobs::MAX_COPIES }}" value="{{ $printing['kitchen_copies'] }}" required></label>
            </div>
            <button class="btn-ghost w-full">{{ __('Save') }}</button>
        </form>
    </div>
</div>
@endsection
