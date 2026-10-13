@extends('layouts.admin')
@section('title', __('Payment settings') . ' · EgMenu Admin')

@section('content')
<h1 class="mb-1 text-2xl font-extrabold">{{ __('Payment settings') }}</h1>
<p class="mb-4 text-sm text-navy-500">{{ __('How restaurants pay their subscription. Gateway keys live in .env; switch a gateway on here once the contract is signed.') }}</p>

<form method="post" action="{{ route('admin.settings.update') }}" class="space-y-4">
    @csrf
    <div class="card grid gap-4 sm:grid-cols-2">
        <h2 class="text-lg font-bold sm:col-span-2">{{ __('Manual transfer') }}</h2>
        <div class="space-y-2">
            <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="pay_instapay_enabled" value="1" @checked($s['pay_instapay_enabled'] === '1') class="size-4 accent-brand-600"> InstaPay</label>
            <input class="field" name="pay_instapay_handle" value="{{ $s['pay_instapay_handle'] }}" placeholder="egweb@instapay" dir="ltr">
        </div>
        <div class="space-y-2">
            <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="pay_vodafone_enabled" value="1" @checked($s['pay_vodafone_enabled'] === '1') class="size-4 accent-brand-600"> {{ __('Vodafone Cash') }}</label>
            <input class="field" name="pay_vodafone_number" value="{{ $s['pay_vodafone_number'] }}" placeholder="010xxxxxxxx" dir="ltr">
        </div>
        <div class="sm:col-span-2">
            <label class="field-label">{{ __('Instructions shown to the restaurant') }}</label>
            <textarea class="field min-h-24 py-2" name="pay_instructions">{{ $s['pay_instructions'] }}</textarea>
        </div>
    </div>

    <div class="card">
        <h2 class="mb-3 text-lg font-bold">{{ __('Online gateways') }}</h2>
        <div class="flex flex-wrap gap-6">
            @foreach (['paymob' => 'Paymob', 'kashier' => 'Kashier', 'geidea' => 'Geidea'] as $key => $label)
                <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="gateway_{{ $key }}_enabled" value="1" @checked($s["gateway_{$key}_enabled"] === '1') class="size-4 accent-brand-600"> {{ $label }}</label>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-navy-500">{{ __('The gateway integrations are built in the billing phase; until then these switches only record the choice.') }}</p>
    </div>

    <div class="card grid gap-4 sm:grid-cols-2">
        <h2 class="text-lg font-bold sm:col-span-2">{{ __('Support contact') }}</h2>
        <div><label class="field-label">{{ __('WhatsApp') }}</label><input class="field" name="support_whatsapp" value="{{ $s['support_whatsapp'] }}" dir="ltr"></div>
        <div><label class="field-label">{{ __('Email') }}</label><input class="field" name="support_email" type="email" value="{{ $s['support_email'] }}" dir="ltr"></div>
    </div>

    <div class="card grid gap-4 sm:grid-cols-2">
        <h2 class="text-lg font-bold sm:col-span-2">{{ __('Custom domains') }}</h2>
        <div><label class="field-label">{{ __('Server IP (restaurants point their domain here)') }}</label><input class="field" name="server_ip" value="{{ $s['server_ip'] }}" dir="ltr" placeholder="54.36.13.152"></div>
        <div><label class="field-label">{{ __('Domain booking link on egweb.com.eg (the domain is added at the end)') }}</label><input class="field" name="domain_cart_url" value="{{ $s['domain_cart_url'] }}" dir="ltr" placeholder="{{ \App\Http\Controllers\Dashboard\DomainController::DEFAULT_CART_URL }}"></div>
    </div>

    <button class="btn-primary">{{ __('Save') }}</button>
</form>
@endsection
