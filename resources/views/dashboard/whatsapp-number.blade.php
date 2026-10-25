@extends('layouts.dashboard')
@section('title', __('Connect your restaurant’s WhatsApp number'))

@php
    /** @var \App\Models\WhatsAppConnection|null $connection */
    $state = $connection?->status;
    // escaped text with each run of Latin words (Meta's English labels, URLs, permission names) isolated, so it
    // keeps its own direction inside an Arabic sentence
    $bidi = fn (string $text): string => (string) preg_replace('/(?<![&\w#])[A-Za-z][\w.\/-]*(?:[ ][A-Za-z][\w.\/-]*)*/', '<bdi>$0</bdi>', e($text));
    $stateLabel = ['draft' => __('Not tested yet'), 'connected' => __('Number connected'), 'failed' => __('Connection failed')];
    $templateLabels = [
        'order_received' => __('Order received'), 'order_ready' => __('Order ready'), 'order_on_way' => __('Order on the way'),
        'reservation_confirmed' => __('Reservation confirmed'), 'loyalty_reward' => __('Loyalty reward earned'),
        'campaign' => __('Campaigns, automations and staff alerts'), 'order_confirm' => __('Guest confirms cash-on-delivery orders'),
    ];
    $templateState = [
        'approved' => [__('Approved'), 'bg-emerald-100 text-emerald-800'], 'pending' => [__('Pending review'), 'bg-amber-100 text-amber-800'],
        'rejected' => [__('Rejected'), 'bg-red-100 text-red-700'], 'paused' => [__('Paused'), 'bg-red-100 text-red-700'],
        'disabled' => [__('Disabled'), 'bg-red-100 text-red-700'], 'error' => [__('Not created'), 'bg-red-100 text-red-700'],
        'missing' => [__('Not sent yet'), 'bg-navy-100 text-navy-700'],
    ];
    $steps = [
        [__('Open business.facebook.com and pick your restaurant’s «Business portfolio», or create one with the restaurant’s official name.'), null],
        [__('Go to «Settings» → «Accounts» → «WhatsApp accounts» and add a WhatsApp account for the restaurant (or open WhatsApp Manager).'), null],
        [__('In WhatsApp Manager choose «Phone numbers» → «Add phone number». Write the display name (your restaurant’s name as guests will see it), the category, then the number.'), __('The number must not be active on the WhatsApp or WhatsApp Business app. See the common problems below to move it.')],
        [__('Confirm the number with the code Meta sends by SMS or by a phone call.'), null],
        [__('Meta reviews the display name (from a few minutes to two days). You can carry on while it is «In review».'), null],
        [__('In WhatsApp Manager open «Billing» and add a payment card: Meta charges your account for each template message sent from your number.'), null],
        [__('Open developers.facebook.com → «My apps» → «Create app». Choose the use case «Connect with customers through WhatsApp», type «Business», and link it to the same business portfolio.'), null],
        [__('In the app: «App settings» → «Basic» → copy the «App secret». You paste it below.'), null],
        [__('Back in business.facebook.com: «Settings» → «Users» → «System users» → «Add». Name it eghub and give it the «Admin» role.'), null],
        [__('Pick that system user → «Assign assets»: under «WhatsApp accounts» choose your account with «Full control», and under «Apps» choose your app with «Full control».'), null],
        [__('Press «Generate token», pick your app, set the expiry to «Never», and tick whatsapp_business_messaging, whatsapp_business_management and business_management. Copy the token at once: Meta shows it only once.'), null],
        [__('In WhatsApp Manager → «Phone numbers» (or in the app → WhatsApp → «API Setup») copy the «Phone number ID» and the «WhatsApp Business Account ID».'), null],
        [__('Paste the four values in the form here and press «Save and connect». We test them, subscribe your app to replies and send our ready message templates to Meta for approval.'), null],
        [__('So guests’ replies (like the «Confirm order» button) reach us: in the app → WhatsApp → «Configuration» → Webhook → «Edit», paste the Callback URL and the Verify token shown here, press «Verify and save», then subscribe to the «messages» field.'), null],
    ];
    $problems = [
        [__('The number is already on WhatsApp'), __('A number can be on the app or on the Cloud API, not both. Back up the chats, then on the phone open WhatsApp → «Settings» → «Account» → «Delete my account», and add the number again in WhatsApp Manager. Or use a new number for the restaurant.')],
        [__('The token expired or was revoked'), __('The status turns to «Connection failed» and your messages go out from the EgHub number by themselves. Generate a new token with the expiry «Never» and paste it here.')],
        [__('The display name is in review or rejected'), __('Messages still go out with the number until the name is approved. If it is rejected, change it in WhatsApp Manager to match your restaurant’s real name (as on your sign or page).')],
        [__('The business is not verified'), __('Meta lets an unverified business start 250 conversations a day. Verify the business from «Settings» → «Security center» → «Start verification» to raise the limit.')],
        [__('A template is pending or rejected'), __('Until Meta approves it, that message goes out from the EgHub number and uses one credit, as before. Press «Refresh status» to check again.')],
    ];
@endphp

@section('content')
<x-dash.page-header :title="__('Connect your restaurant’s WhatsApp number')" :help="__('Your guests get your WhatsApp messages from your own number and with your name, instead of the EgHub number. You connect it once from your Meta (Facebook) account; it takes about 20 minutes.')">
    <x-slot:actions>
        <a href="{{ route('dash.settings', ['tab' => 'messages']) }}#whatsapp" class="btn-ghost">{{ __('WhatsApp settings') }}</a>
    </x-slot:actions>
</x-dash.page-header>

@if (! $allowed)
    <div class="card max-w-2xl">
        <p class="text-navy-700">{{ \App\Services\Entitlements::current()->upgradeMessage('whatsapp_notifications') }}</p>
        @can('billing.manage')<a href="{{ route('dash.billing') }}" class="btn-primary mt-4">{{ __('Add-ons') }}</a>@endcan
        {{-- a number connected on an earlier plan: its stored token can always be deleted --}}
        @if ($connection && ! $support)
            <form method="post" action="{{ route('dash.whatsapp-number.disconnect') }}" class="mt-4 flex flex-wrap items-center gap-3 border-t border-navy-100 pt-4" onsubmit="return confirm(@js(__('Disconnect this number? Your messages go out from the EgHub number again, and the stored token is deleted.')))" data-wa-number-disconnect>
                @csrf
                <span class="text-sm text-navy-600" dir="ltr">{{ $connection->display_phone ?: $connection->phone_number_id }}</span>
                <button class="btn-ghost text-red-600">{{ __('Disconnect the number') }}</button>
            </form>
        @endif
    </div>
@else
    @if ($connection)
        <section class="card mb-4 max-w-5xl space-y-3" data-wa-number-status="{{ $state }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-xl font-extrabold" dir="ltr">{{ $connection->display_phone ?: $connection->phone_number_id }}</div>
                    @if ($connection->verified_name)<div class="text-sm text-navy-600">{{ $connection->verified_name }}</div>@endif
                    <div class="mt-1 flex flex-wrap gap-2 text-xs">
                        <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $state === 'connected', 'bg-red-100 text-red-700' => $state === 'failed', 'bg-amber-100 text-amber-800' => $state === 'draft'])>{{ $stateLabel[$state] ?? $state }}</span>
                        @if ($connection->name_status)<span class="badge bg-navy-100 text-navy-700">{{ __('Display name') }}: {{ $connection->name_status === 'approved' ? __('Approved') : __('In review or needs action') }}</span>@endif
                        @if ($connection->quality_rating)<span class="badge bg-navy-100 text-navy-700">{{ __('Quality') }}: {{ ucfirst($connection->quality_rating) }}</span>@endif
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <form method="post" action="{{ route('dash.whatsapp-number.test') }}">@csrf<button class="btn-primary">{{ __('Test the connection') }}</button></form>
                    @unless ($support)
                        <form method="post" action="{{ route('dash.whatsapp-number.disconnect') }}" onsubmit="return confirm(@js(__('Disconnect this number? Your messages go out from the EgHub number again, and the stored token is deleted.')))">@csrf<button class="btn-ghost text-red-600">{{ __('Disconnect the number') }}</button></form>
                    @endunless
                </div>
            </div>
            @if ($connection->last_error)<p class="rounded-lg bg-red-50 p-3 text-sm text-red-700" data-wa-number-error>{{ $connection->last_error }}</p>@endif
            <dl class="grid gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-navy-500">{{ __('Last test') }}</dt><dd>{{ $connection->checked_at?->diffForHumans() ?? '—' }}</dd></div>
                <div><dt class="text-navy-500">{{ __('Replies subscription') }}</dt><dd>{{ $connection->subscribed_at ? __('Subscribed') : __('Not yet') }}</dd></div>
                <div><dt class="text-navy-500">{{ __('Last reply from your number') }}</dt><dd>{{ $connection->last_inbound_at?->diffForHumans() ?? __('None yet') }}</dd></div>
            </dl>
        </section>
    @endif

    <div class="grid max-w-5xl gap-4 lg:grid-cols-2">
        <div class="space-y-4">
            <section class="card">
                <h2 class="section-title">{{ __('How to connect, step by step') }}</h2>
                <p class="mt-1 text-sm text-navy-500">{{ __('Before you start: a mobile or landline number that is not on WhatsApp now, and the Facebook account you use for your business. A computer is easier than a phone for these steps.') }}</p>
                <ol class="mt-3 space-y-3" data-wa-number-guide>
                    @foreach ($steps as $i => [$text, $note])
                        <li class="flex items-start gap-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-navy-800 text-xs font-extrabold text-white">{{ $i + 1 }}</span>
                            <div class="min-w-0 text-sm leading-relaxed text-navy-700">{!! $bidi($text) !!}@if ($note)<span class="mt-1 block text-xs text-amber-700">{!! $bidi($note) !!}</span>@endif</div>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="card">
                <h2 class="section-title">{{ __('Common problems') }}</h2>
                <div class="mt-2 space-y-2">
                    @foreach ($problems as [$q, $a])
                        <details class="rounded-xl border border-navy-100 p-3">
                            <summary class="cursor-pointer text-sm font-semibold">{{ $q }}</summary>
                            <p class="mt-2 text-sm text-navy-600">{!! $bidi($a) !!}</p>
                        </details>
                    @endforeach
                </div>
            </section>
        </div>

        <div class="space-y-4">
            <section class="card space-y-3">
                <h2 class="section-title">{{ __('Your number’s details') }}</h2>
                @if ($support)
                    <p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800" data-wa-number-support>{{ __('Not available while signed in from the admin.') }} {{ __('Only the restaurant can enter or change its WhatsApp token.') }}</p>
                @else
                    <form method="post" action="{{ route('dash.whatsapp-number.save') }}" class="space-y-3" autocomplete="off">
                        @csrf
                        <div>
                            <label class="field-label" for="waba_id">{{ __('WhatsApp Business account ID') }}</label>
                            <input class="field" id="waba_id" name="waba_id" required inputmode="numeric" dir="ltr" value="{{ old('waba_id', $connection?->waba_id) }}" placeholder="102290129340398">
                        </div>
                        <div>
                            <label class="field-label" for="phone_number_id">{{ __('Phone number ID') }}</label>
                            <input class="field" id="phone_number_id" name="phone_number_id" required inputmode="numeric" dir="ltr" value="{{ old('phone_number_id', $connection?->phone_number_id) }}" placeholder="106540352242922">
                        </div>
                        <div>
                            <label class="field-label" for="access_token">{{ __('Access token') }}</label>
                            <input class="field" id="access_token" name="access_token" type="password" dir="ltr" @required(! $connection) autocomplete="new-password"
                                placeholder="{{ $connection ? $connection->maskedToken() : 'EAAG…' }}">
                            @if ($connection)<p class="mt-1 text-xs text-navy-500">{{ __('Saved: :token. Leave it empty to keep it.', ['token' => $connection->maskedToken()]) }}</p>@endif
                        </div>
                        <div>
                            <label class="field-label" for="app_secret">{{ __('App secret') }}</label>
                            <input class="field" id="app_secret" name="app_secret" type="password" dir="ltr" @required(! $connection?->app_secret) autocomplete="new-password"
                                placeholder="{{ $connection?->app_secret ? '••••••••' : '' }}">
                            <p class="mt-1 text-xs text-navy-500">{{ __('It proves the replies really come from your app. Leave it empty to keep the saved one.') }}</p>
                        </div>
                        <button class="btn-primary w-full">{{ __('Save and connect') }}</button>
                        <p class="text-xs text-navy-500">{{ __('The token and the secret are stored encrypted and never shown again, not even to EgHub support.') }}</p>
                    </form>
                @endif
            </section>

            <section class="card space-y-2" data-wa-number-webhook>
                <h2 class="section-title">{{ __('Replies from your guests (Webhook)') }}</h2>
                @if ($connection)
                    <p class="text-sm text-navy-600">{{ __('In your app → WhatsApp → «Configuration» → Webhook, paste these two values, then subscribe to «messages».') }}</p>
                    @foreach ([[__('Callback URL'), $callbackUrl], [__('Verify token'), $support ? '••••••••' : $connection->verify_token]] as [$label, $value])
                        <div>
                            <div class="field-label">{{ $label }}</div>
                            <div class="flex items-center gap-2">
                                <code class="min-w-0 flex-1 truncate rounded-lg bg-navy-50 px-3 py-2 text-xs" dir="ltr">{{ $value }}</code>
                                @unless ($support)<button type="button" class="btn-ghost min-h-9 px-3 text-xs" data-copy="{{ $value }}">{{ __('Copy') }}</button>@endunless
                            </div>
                        </div>
                    @endforeach
                    <p class="text-xs text-navy-500">{{ $connection->webhook_verified_at ? __('Meta verified this URL :when.', ['when' => $connection->webhook_verified_at->diffForHumans()]) : __('Meta has not verified this URL yet.') }}</p>
                @else
                    <p class="text-sm text-navy-500">{{ __('Your own Callback URL and Verify token show here after you save your details.') }}</p>
                @endif
            </section>

            @if ($connection)
                <section class="card space-y-3" data-wa-number-templates>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="section-title">{{ __('Message templates in your account') }}</h2>
                        @if ($connection->isConnected() && ! $support)
                            <form method="post" action="{{ route('dash.whatsapp-number.templates') }}">@csrf<button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Refresh status') }}</button></form>
                        @endif
                    </div>
                    <p class="text-sm text-navy-500">{{ __('Meta reviews each template (usually minutes, up to a day). Each message goes out from your number once its template is approved.') }}</p>
                    <ul class="divide-y divide-navy-100 text-sm">
                        @foreach ($templates as $name => $tpl)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span class="min-w-0"><span class="font-semibold">{{ $templateLabels[$tpl['key']] ?? $tpl['key'] }}</span> <span class="text-xs text-navy-400" dir="ltr">{{ $name }}</span></span>
                                <span class="flex flex-wrap gap-1">
                                    @foreach ($tpl['languages'] as $lang)
                                        @php
                                            [$label, $tone] = $templateState[$connection->templateStatus($name, $lang)] ?? $templateState['pending'];
                                        @endphp
                                        <span class="badge {{ $tone }}" data-wa-template="{{ $name }}:{{ $lang }}">{{ strtoupper($lang) }} · {{ $label }}</span>
                                    @endforeach
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($connection->templates_checked_at)<p class="text-xs text-navy-500">{{ __('Checked :when.', ['when' => $connection->templates_checked_at->diffForHumans()]) }}</p>@endif
                </section>
            @endif

            <section class="card space-y-2 bg-brand-50" data-wa-number-credits>
                <h2 class="section-title">{{ __('What it costs') }}</h2>
                <p class="text-sm text-navy-700">{{ __('Messages sent from your number are billed by Meta directly to the payment card in your WhatsApp account, so EgHub takes no credit for them. Replies inside the 24 hours after a guest writes are free on both.') }}</p>
                <p class="text-sm text-navy-700">{{ __('A message that goes out from the EgHub number (its template is not approved yet, or the connection stopped) uses one credit, as before.') }}</p>
            </section>

            <p class="px-1 text-xs text-navy-500">{{ __('Coming soon: connect with one button through Meta’s Embedded Signup, without these steps.') }}</p>
        </div>
    </div>
    <script>
        document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = @js(__('Copied')); } catch (e) { prompt('', b.dataset.copy); }
        }));
    </script>
@endif
@endsection
