{{-- The "Automations" tab of the campaigns page: three cards (welcome, winback, birthday) with their toggles and results. --}}
<p class="muted mb-4">{{ __('Messages that go out by themselves when something happens to a guest. Each message uses one credit, is sent inside the hours you choose, and never reaches the same guest twice in a row.') }}
    {{ __('Your balance') }}: <b>{{ number_format($balance) }}</b>
    @can('billing.manage')<a href="{{ route('dash.billing') }}#whatsapp-credits" class="text-brand-700 underline">{{ __('Top up') }}</a>@endcan
</p>
@if ($balance < 1)
    <p class="mb-4 rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700">{{ __('Your WhatsApp credits ran out') }}. {{ __('Automations pause until you top up.') }}</p>
@endif

<div class="grid gap-4 lg:grid-cols-3" data-automations>
    @foreach ($automations as $kind => $a)
        @include('dashboard.campaigns.automation-card', ['r' => $results[$kind]])
    @endforeach
</div>
