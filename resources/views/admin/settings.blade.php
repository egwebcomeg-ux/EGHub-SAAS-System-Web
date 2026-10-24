@extends('layouts.admin')
@section('title', __('Payment settings') . ' · EgHub Admin')

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

    <div class="card space-y-4" id="online-payments" data-online-payments>
        <div>
            <h2 class="text-lg font-bold">{{ __('Online payments (Kashier)') }}</h2>
            <p class="text-sm text-navy-500">{{ __('Restaurants pay their invoices on Kashier’s page; the plan switches on by itself when Kashier confirms. Installments are offered on yearly invoices and from the minimum amount below.') }}</p>
        </div>
        <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="gateway_kashier_enabled" value="1" @checked($s['gateway_kashier_enabled'] === '1') class="size-4 accent-brand-600"> {{ __('Accept online payments') }}</label>
        @unless ($kashier['configured'])
            <p class="rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">{{ __('Kashier keys are missing in .env: restaurants do not see online payment until they are set.') }}</p>
        @endunless

        <div class="grid gap-4 sm:grid-cols-2">
            <fieldset>
                <legend class="field-label">{{ __('Pay now') }}</legend>
                <div class="space-y-1.5">
                    @foreach (\App\Billing\OnlinePayments::METHODS as $key => $method)
                        @continue($method['installments'])
                        <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="kashier_methods[]" value="{{ $key }}" @checked(in_array($key, $kashier['methods'], true)) class="size-4 accent-brand-600"> {{ \App\Billing\OnlinePayments::label($key) }}</label>
                    @endforeach
                </div>
            </fieldset>
            <fieldset>
                <legend class="field-label">{{ __('Installments') }}</legend>
                <div class="grid grid-cols-2 gap-1.5">
                    @foreach (\App\Billing\OnlinePayments::METHODS as $key => $method)
                        @continue(! $method['installments'])
                        <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="kashier_methods[]" value="{{ $key }}" @checked(in_array($key, $kashier['methods'], true)) class="size-4 accent-brand-600"> {{ \App\Billing\OnlinePayments::label($key) }}</label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-navy-500">{{ __('Tick only the providers activated on your Kashier account; the site promises installments only with the ones ticked here.') }}</p>
            </fieldset>
            <div>
                <label class="field-label">{{ __('Also offer installments from this amount (EGP, optional)') }}</label>
                <input class="field" type="number" min="0" step="1" name="kashier_installments_min" value="{{ $s['kashier_installments_min'] }}" dir="ltr" placeholder="0">
            </div>
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-6">
            @foreach ($kashier['keys'] as $key)
                <dt class="font-semibold" dir="ltr">{{ $key['env'] }}</dt>
                <dd><span class="badge {{ $key['set'] ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-700' }}">{{ $key['set'] ? '✓ ' . __('Set') : '✗ ' . __('Not set') }}</span></dd>
            @endforeach
            <dt class="font-semibold" dir="ltr">KASHIER_MODE</dt>
            <dd><span class="badge {{ $kashier['mode'] === 'live' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $kashier['mode'] === 'live' ? __('Live') : __('Test') }}</span></dd>
            <dt class="font-semibold">{{ __('Webhook URL') }}</dt>
            <dd class="flex flex-wrap items-center gap-2" dir="ltr">
                <code class="rounded bg-navy-50 px-2 py-1">{{ $kashier['webhook_url'] }}</code>
                <button type="button" class="btn-ghost min-h-8 px-2 text-xs" data-copy="{{ $kashier['webhook_url'] }}">{{ __('Copy') }}</button>
            </dd>
            <dt class="font-semibold">{{ __('Return address') }}</dt>
            <dd dir="ltr" class="text-start"><code class="rounded bg-navy-50 px-2 py-1">{{ $kashier['return_url'] }}</code></dd>
        </dl>
        <p class="text-xs text-navy-500">{{ __('Both addresses are sent with every payment, so nothing has to be pasted in Kashier. Edit .env and run php artisan config:clear to change the keys.') }}</p>
    </div>

    <div class="card grid gap-4 sm:grid-cols-2" data-launch-offer-settings>
        <h2 class="text-lg font-bold sm:col-span-2">{{ __('Launch offer: second branch free') }}</h2>
        <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="launch_offer_branch_enabled" value="1" @checked($s['launch_offer_branch_enabled'] !== '0') class="size-4 accent-brand-600"> {{ __('New Complete subscriptions get their first extra branch free for 12 months') }}</label>
        <div><label class="field-label">{{ __('Offer ends on (optional)') }}</label><input class="field" type="date" name="launch_offer_branch_ends" value="{{ $s['launch_offer_branch_ends'] }}" dir="ltr"></div>
        <p class="text-xs text-navy-500 sm:col-span-2">{{ __('Each subscription keeps the end date it got, so switching the offer off later does not take it back from restaurants that already have it.') }}</p>
    </div>

    <div class="card grid gap-4 sm:grid-cols-2" data-launch-price-settings>
        <h2 class="text-lg font-bold sm:col-span-2">{{ __('Launch price') }}</h2>
        <div><label class="field-label" for="s-launch-ends">{{ __('Launch price valid until (optional)') }}</label><input class="field" id="s-launch-ends" type="date" name="launch_offer_ends_at" value="{{ $s['launch_offer_ends_at'] }}" dir="ltr"></div>
        <p class="text-xs text-navy-500 sm:col-span-2">{{ __('Shown next to the crossed-out price on the site. After this day the crossed-out price hides by itself; change the plan price in Plans when the offer ends.') }}</p>
    </div>

    <div class="card grid gap-4 sm:grid-cols-2" data-legal-settings>
        <h2 class="text-lg font-bold sm:col-span-2">{{ __('Company legal details') }}</h2>
        <div><label class="field-label" for="s-legal-entity">{{ __('Legal name of the company') }}</label><input class="field" id="s-legal-entity" name="legal_entity" value="{{ $s['legal_entity'] }}" maxlength="160"></div>
        <div><label class="field-label" for="s-legal-cr">{{ __('Commercial register number') }}</label><input class="field" id="s-legal-cr" name="commercial_register" value="{{ $s['commercial_register'] }}" dir="ltr" maxlength="60"></div>
        <div><label class="field-label" for="s-legal-tax">{{ __('Tax registration number') }}</label><input class="field" id="s-legal-tax" name="tax_id" value="{{ $s['tax_id'] }}" dir="ltr" maxlength="60"></div>
        <div><label class="field-label" for="s-legal-address">{{ __('Registered address') }}</label><input class="field" id="s-legal-address" name="legal_address" value="{{ $s['legal_address'] }}" maxlength="250"></div>
        <p class="text-xs text-navy-500 sm:col-span-2">{{ __('Shown in the site footer and on the terms, privacy and refund pages once filled; empty fields are hidden.') }}</p>
    </div>

    <div class="card grid gap-4 sm:grid-cols-2">
        <h2 class="text-lg font-bold sm:col-span-2">{{ __('Support contact') }}</h2>
        <div><label class="field-label">{{ __('Support WhatsApp') }}</label><input class="field" name="support_whatsapp" value="{{ $s['support_whatsapp'] }}" dir="ltr" placeholder="010xxxxxxxx"></div>
        <div><label class="field-label">{{ __('Sales WhatsApp') }}</label><input class="field" name="sales_whatsapp" value="{{ $s['sales_whatsapp'] }}" dir="ltr" placeholder="010xxxxxxxx"></div>
        <div><label class="field-label">{{ __('Support email') }}</label><input class="field" name="support_email" type="email" value="{{ $s['support_email'] }}" dir="ltr"></div>
        <div><label class="field-label">{{ __('Public contact email (site footer)') }}</label><input class="field" name="contact_email" type="email" value="{{ $s['contact_email'] }}" dir="ltr" placeholder="hi@eghub.app"></div>
        <p class="text-xs text-navy-500 sm:col-span-2">{{ __('The site footer shows the sales and support numbers as WhatsApp links and the public email; empty fields are hidden.') }}</p>
    </div>

    <div class="card grid gap-3 sm:grid-cols-2" data-social-settings>
        <h2 class="text-lg font-bold sm:col-span-2">{{ __('Social media') }}</h2>
        @foreach (\App\Support\SocialLinks::NETWORKS as $network => [$networkLabel])
            <div><label class="field-label">{{ $networkLabel }}</label><input class="field" name="social_{{ $network }}" type="url" value="{{ $s['social_' . $network] }}" dir="ltr" placeholder="https://"></div>
        @endforeach
        <p class="text-xs text-navy-500 sm:col-span-2">{{ __('EgHub’s own accounts. Each filled link shows as an icon in the site footer; empty fields are hidden.') }}</p>
    </div>

    <div class="card space-y-3" id="whatsapp-verification">
        <h2 class="text-lg font-bold">{{ __('WhatsApp phone confirmation') }}</h2>
        <p class="text-sm text-navy-500">{{ __('Owners confirm their mobile by sending one ready message (EGMENU + code) to the platform number. These values come from .env and are shown here so you can paste them into Meta.') }}</p>
        <dl class="grid gap-3 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-6">
            <dt class="font-semibold">{{ __('Platform WhatsApp number') }}</dt>
            <dd dir="ltr" class="text-start">
                @if ($whatsapp['number'])
                    +{{ $whatsapp['number'] }}
                    <span class="text-navy-500">({{ $whatsapp['source'] === 'env' ? 'WHATSAPP_NUMBER' : __('the support WhatsApp above') }})</span>
                @else
                    <span class="text-amber-700">{{ __('Not set — owners are not asked to confirm their number.') }}</span>
                @endif
            </dd>
            <dt class="font-semibold">{{ __('Webhook URL') }}</dt>
            <dd class="flex flex-wrap items-center gap-2" dir="ltr">
                <code class="rounded bg-navy-50 px-2 py-1">{{ $whatsapp['webhook_url'] }}</code>
                <button type="button" class="btn-ghost min-h-8 px-2 text-xs" data-copy="{{ $whatsapp['webhook_url'] }}">{{ __('Copy') }}</button>
            </dd>
            <dt class="font-semibold">{{ __('Verify token') }} <span class="font-normal text-navy-500">(WHATSAPP_VERIFY_TOKEN)</span></dt>
            <dd class="flex flex-wrap items-center gap-2" dir="ltr">
                @if ($whatsapp['verify_token'] !== '')
                    <code class="rounded bg-navy-50 px-2 py-1">{{ $whatsapp['verify_token'] }}</code>
                    <button type="button" class="btn-ghost min-h-8 px-2 text-xs" data-copy="{{ $whatsapp['verify_token'] }}">{{ __('Copy') }}</button>
                @else
                    <span class="text-amber-700">{{ __('Not set') }}</span>
                @endif
            </dd>
            <dt class="font-semibold">{{ __('App secret') }} <span class="font-normal text-navy-500">(WHATSAPP_APP_SECRET)</span></dt>
            <dd>
                @if ($whatsapp['app_secret_set'])
                    <span class="badge bg-emerald-100 text-emerald-800">{{ __('Set') }}</span>
                @else
                    <span class="badge bg-red-100 text-red-700">{{ __('Missing — every Meta payload is refused') }}</span>
                @endif
            </dd>
            <dt class="font-semibold">{{ __('Provider secret') }} <span class="font-normal text-navy-500">(WHATSAPP_WEBHOOK_SECRET)</span></dt>
            <dd>
                <span class="badge {{ $whatsapp['provider_secret_set'] ? 'bg-emerald-100 text-emerald-800' : 'bg-navy-100 text-navy-600' }}">{{ $whatsapp['provider_secret_set'] ? __('Set') : __('Not set') }}</span>
                <span class="text-navy-500">{{ __('Only for a non-Meta provider posting {from, text} with the X-EgMenu-Secret header.') }}</span>
            </dd>
        </dl>
        <p class="text-xs text-navy-500">{{ __('In Meta for Developers: WhatsApp → Configuration → Webhook: paste the URL and the verify token, then subscribe to the "messages" field. Edit .env and run php artisan config:clear to change these.') }}</p>
    </div>

    <div class="card grid gap-4 sm:grid-cols-2">
        <h2 class="text-lg font-bold sm:col-span-2">{{ __('Custom domains') }}</h2>
        <div><label class="field-label">{{ __('Server IP (restaurants point their domain here)') }}</label><input class="field" name="server_ip" value="{{ $s['server_ip'] }}" dir="ltr" placeholder="54.36.13.152"></div>
        <div><label class="field-label">{{ __('Domain booking link on egweb.com.eg (the domain is added at the end)') }}</label><input class="field" name="domain_cart_url" value="{{ $s['domain_cart_url'] }}" dir="ltr" placeholder="{{ \App\Http\Controllers\Dashboard\DomainController::DEFAULT_CART_URL }}"></div>
    </div>

    <button class="btn-primary">{{ __('Save') }}</button>
</form>
<script>
    document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = @js(__('Copied')); } catch (e) { prompt('', b.dataset.copy); }
    }));
</script>
@endsection
