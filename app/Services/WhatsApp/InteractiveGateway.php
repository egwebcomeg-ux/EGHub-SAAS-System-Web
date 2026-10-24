<?php

namespace App\Services\WhatsApp;

/**
 * A provider that can put quick-reply buttons under a template and answer a guest with free text inside the 24-hour
 * window the guest opened. A driver without it still works: the buttons become a link to the confirmation page in
 * the text (App\Services\WhatsApp\WhatsApp::deliver) and replies go out as a plain send of the text.
 */
interface InteractiveGateway extends WhatsAppGateway
{
    /**
     * Sends one templated message with its quick-reply buttons and returns the provider's message id.
     *
     * @param  array<string,string>  $params  body parameters in template order
     * @param  list<array{id:string, title:string, payload:string}>  $buttons  in the approved template's order
     *
     * @throws WhatsAppException
     */
    public function sendWithButtons(string $to, string $templateKey, array $params, array $buttons, string $locale = 'ar', string $text = ''): string;

    /**
     * Free-form text to a guest who wrote to us in the last 24 hours (no template). Returns the provider's message id.
     *
     * @throws WhatsAppException
     */
    public function reply(string $to, string $text): string;
}
