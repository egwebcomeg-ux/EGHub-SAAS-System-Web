<?php

namespace App\Services\WhatsApp;

/**
 * A WhatsApp sending provider (config whatsapp.driver). Drivers know nothing about credits or restaurants:
 * App\Services\WhatsApp\WhatsApp decides whether a message may go out, the driver only delivers it.
 */
interface WhatsAppGateway
{
    /**
     * Sends one templated message and returns the provider's message id.
     *
     * @param  string  $to  international digits without "+", e.g. 201012345678
     * @param  string  $templateKey  a key of config('whatsapp.templates')
     * @param  array<string,string>  $params  body parameters in template order (App\Services\WhatsApp\Templates::PARAMS)
     * @param  string  $locale  ar | en
     * @param  string  $text  the rendered text, for providers that send free text instead of templates
     *
     * @throws WhatsAppException when the provider refuses or cannot be reached
     */
    public function send(string $to, string $templateKey, array $params, string $locale = 'ar', string $text = ''): string;
}
