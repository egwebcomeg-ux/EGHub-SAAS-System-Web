{{-- Shown when WhatsApp messages were skipped this week because the credit balance is empty. --}}
@php
    $waRights = \App\Services\Entitlements::current();
    $waEmpty = auth()->user()->can('billing.manage')
        && ($waRights->allows('whatsapp_notifications') || $waRights->allows('campaigns'))
        && app(\App\Billing\Credits::class)->balance(tenant()) < 1
        && \App\Models\WhatsAppMessage::query()->where('error', \App\Models\WhatsAppMessage::NO_CREDITS)->where('created_at', '>=', now()->subDays(7))->exists();
@endphp
@if ($waEmpty && ! request()->routeIs('dash.billing*'))
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-red-50 px-4 py-3 text-sm font-semibold text-red-800" data-whatsapp-empty>
        <span>{{ __('Your WhatsApp credits ran out') }} — {{ __('messages to your guests are not being sent.') }}</span>
        <a href="{{ route('dash.billing') }}#whatsapp-credits" class="btn-primary min-h-9 px-3">{{ __('Top up') }}</a>
    </div>
@endif
