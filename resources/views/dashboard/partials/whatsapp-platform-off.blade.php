{{-- Until the EgHub number's templates are approved (config whatsapp.platform_sending) --}}
@unless (\App\Services\WhatsApp\WhatsApp::platformSends())
    <p class="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900" data-whatsapp-platform-off>{{ __('Automatic WhatsApp messages from the EgHub number are not available yet, so no credits are sold or used. You can connect your own WhatsApp Business number in the meantime.') }}</p>
@endunless
