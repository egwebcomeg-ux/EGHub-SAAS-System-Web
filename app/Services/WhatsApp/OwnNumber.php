<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * "Bring your own number": a restaurant sends its WhatsApp messages from its own WhatsApp Business number, through
 * the official Cloud API, with the WABA id, phone number id, permanent system-user token and app secret its owner
 * pasted on the dashboard (App\Models\WhatsAppConnection).
 *
 * A message goes out from the restaurant's number only when the connection is "connected" and the message's
 * template is approved in the restaurant's own WABA (sender()); otherwise it goes out from the EgHub number exactly as
 * before. Meta bills template messages sent from the restaurant's number to the restaurant's own WABA, so those take
 * no EgHub credit (App\Services\WhatsApp\WhatsApp::deliver).
 *
 * The Graph calls here: check() (the phone number and the WABA, and that the number belongs to it), subscribe()
 * (POST /{waba}/subscribed_apps so the restaurant's app gets the replies), syncTemplates() (creates our templates in
 * the restaurant's WABA and reads back their approval status). The token is only ever the Authorization header and is
 * scrubbed from any error kept.
 *
 * Later, with Meta Tech Provider approval, Embedded Signup can fill the same row (WABA, phone number, a business token)
 * and subscribe our own app instead; the inbound webhook already routes such numbers by metadata.phone_number_id.
 */
final class OwnNumber
{
    /** Meta error codes meaning the token no longer works (expired, revoked, password changed). */
    public const TOKEN_ERRORS = [190];

    /** The restaurant's connection, whatever its state (current tenant). */
    public static function current(): ?WhatsAppConnection
    {
        return tenancy()->initialized ? WhatsAppConnection::query()->first() : null;
    }

    /**
     * The connection that sends $templateKey in $locale from the restaurant's own number, or null when the message
     * goes out from the EgHub number (not connected, failed, or that template not approved yet).
     */
    public static function sender(string $templateKey, string $locale): ?WhatsAppConnection
    {
        $connection = self::current();
        if (! $connection?->isConnected()) {
            return null;
        }

        return $connection->templateApproved(self::templateName($templateKey), self::language($locale)) ? $connection : null;
    }

    /** Whether $templateKey would go out from the restaurant's own number now (so it needs no EgHub credit). */
    public static function covers(string $templateKey): bool
    {
        return self::sender($templateKey, WhatsApp::locale()) !== null;
    }

    public static function templateName(string $key): string
    {
        return (string) (config("whatsapp.templates.$key") ?? $key);
    }

    public static function language(string $locale): string
    {
        return (string) config("whatsapp.languages.$locale", $locale);
    }

    /**
     * Our message templates as Meta wants them: one per template name (the first key using a name defines it) and
     * per language.
     *
     * @return list<array{key: string, name: string, language: string, category: string, components: list<array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        $out = [];
        $seen = [];
        foreach (array_keys((array) config('whatsapp.templates')) as $key) {
            $name = self::templateName($key);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            foreach ((array) config('whatsapp.languages') as $locale => $language) {
                $body = Templates::metaBody($key, $locale);
                if ($body === null) {
                    continue;
                }
                $bodyComponent = ['type' => 'BODY', 'text' => $body];
                $examples = Templates::metaExamples($key, $locale);
                if ($examples !== []) {
                    $bodyComponent['example'] = ['body_text' => [$examples]];
                }
                $components = [$bodyComponent];
                if (isset(Templates::BUTTONS[$key])) {
                    $components[] = ['type' => 'BUTTONS', 'buttons' => array_values(array_map(
                        fn (array $titles) => ['type' => 'QUICK_REPLY', 'text' => $titles[$locale === 'en' ? 1 : 0]],
                        Templates::BUTTONS[$key],
                    ))];
                }
                $out[] = ['key' => $key, 'name' => $name, 'language' => (string) $language, 'category' => Templates::metaCategory($key), 'components' => $components];
            }
        }

        return $out;
    }

    /**
     * "اختبر الربط": reads the phone number (display number, verified name, quality, name status) and the WABA, and
     * checks the number belongs to that WABA. Saves the outcome on the row; returns whether it is connected.
     */
    public function check(WhatsAppConnection $connection): bool
    {
        try {
            $phone = $this->graph($connection)->get($this->url($connection->phone_number_id), [
                'fields' => 'display_phone_number,verified_name,quality_rating,name_status',
            ]);
            if ($phone->failed()) {
                return $this->fail($connection, $phone);
            }
            $waba = $this->graph($connection)->get($this->url($connection->waba_id), ['fields' => 'id,name']);
            if ($waba->failed()) {
                return $this->fail($connection, $waba);
            }
            $numbers = $this->graph($connection)->get($this->url($connection->waba_id.'/phone_numbers'), ['fields' => 'id', 'limit' => 100]);
            if ($numbers->failed()) {
                return $this->fail($connection, $numbers);
            }
        } catch (ConnectionException) {
            return $this->fail($connection, null, __('Meta could not be reached. Try again in a minute.'));
        }
        if (! collect((array) $numbers->json('data', []))->pluck('id')->map(fn ($id) => (string) $id)->contains($connection->phone_number_id)) {
            return $this->fail($connection, null, __('This phone number ID is not in this WhatsApp Business account. Copy both IDs from the same account.'));
        }

        $connection->forceFill([
            'status' => 'connected', 'last_error' => null, 'checked_at' => now(),
            'display_phone' => mb_substr((string) $phone->json('display_phone_number', ''), 0, 30) ?: null,
            'verified_name' => mb_substr((string) $phone->json('verified_name', ''), 0, 120) ?: null,
            'quality_rating' => mb_substr(strtolower((string) $phone->json('quality_rating', '')), 0, 20) ?: null,
            'name_status' => mb_substr(strtolower((string) $phone->json('name_status', '')), 0, 40) ?: null,
        ])->save();

        return true;
    }

    /** Subscribes the restaurant's app to its WABA's webhooks, so guests' replies and delivery statuses reach us. */
    public function subscribe(WhatsAppConnection $connection): bool
    {
        try {
            $response = $this->graph($connection)->post($this->url($connection->waba_id.'/subscribed_apps'));
        } catch (ConnectionException) {
            return false;
        }
        if ($response->failed() || $response->json('success') === false) {
            $connection->forceFill(['last_error' => $this->explain($connection, $response)])->save();

            return false;
        }
        $connection->forceFill(['subscribed_at' => now()])->save();

        return true;
    }

    /** Best effort when the owner disconnects: our callbacks stop. Never throws. */
    public function unsubscribe(WhatsAppConnection $connection): void
    {
        try {
            $this->graph($connection)->delete($this->url($connection->waba_id.'/subscribed_apps'));
        } catch (\Throwable) {
            // the row is deleted anyway; the owner can also remove the app from Meta
        }
    }

    /**
     * Reads every template of the restaurant's WABA and, with $create, submits the ones of ours that are missing.
     * Saves name => language => status on the row. Returns how many templates were submitted.
     */
    public function syncTemplates(WhatsAppConnection $connection, bool $create = true): int
    {
        $states = [];
        try {
            $list = $this->graph($connection)->get($this->url($connection->waba_id.'/message_templates'), ['fields' => 'name,language,status', 'limit' => 250]);
            if ($list->failed()) {
                $connection->forceFill(['last_error' => $this->explain($connection, $list)])->save();

                return 0;
            }
            foreach ((array) $list->json('data', []) as $row) {
                $states[(string) ($row['name'] ?? '')][(string) ($row['language'] ?? '')] = self::state((string) ($row['status'] ?? ''));
            }

            $submitted = 0;
            $error = null;
            foreach (self::definitions() as $definition) {
                if (! $create || isset($states[$definition['name']][$definition['language']])) {
                    continue;
                }
                $response = $this->graph($connection)->post($this->url($connection->waba_id.'/message_templates'), [
                    'name' => $definition['name'], 'language' => $definition['language'],
                    'category' => $definition['category'], 'components' => $definition['components'],
                ]);
                if ($response->failed()) {
                    $states[$definition['name']][$definition['language']] = 'error';
                    $error ??= $this->explain($connection, $response);

                    continue;
                }
                $states[$definition['name']][$definition['language']] = self::state((string) $response->json('status', 'PENDING'));
                $submitted++;
            }
        } catch (ConnectionException) {
            return 0;
        }

        // only our own templates are kept on the row
        $ours = collect(self::definitions())->pluck('name')->unique()->all();
        $connection->forceFill([
            'templates' => array_intersect_key($states, array_flip($ours)), 'templates_checked_at' => now(),
            'last_error' => $error !== null ? mb_substr(__('Some templates were not created:').' '.$error, 0, 255) : $connection->last_error,
        ])->save();

        return $submitted;
    }

    /** Meta's template status in our words: approved | pending | rejected | paused | disabled. */
    public static function state(string $status): string
    {
        $status = strtolower($status);

        return match (true) {
            in_array($status, ['approved', 'rejected', 'paused', 'disabled'], true) => $status,
            $status === 'error' => 'error',
            default => 'pending', // pending, in_appeal, pending_deletion, limit_exceeded...
        };
    }

    private function graph(WhatsAppConnection $connection): PendingRequest
    {
        return Http::withToken((string) $connection->access_token)->acceptJson()->asJson()->timeout((int) config('whatsapp.timeout', 15));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('whatsapp.meta.base_url'), '/').'/'.config('whatsapp.meta.version').'/'.ltrim($path, '/');
    }

    /** Marks the connection failed with a clear reason; always returns false. */
    private function fail(WhatsAppConnection $connection, ?Response $response, ?string $reason = null): bool
    {
        $connection->forceFill([
            'status' => 'failed', 'checked_at' => now(),
            'last_error' => mb_substr($reason ?? $this->explain($connection, $response), 0, 255),
        ])->save();

        return false;
    }

    /** What went wrong, in the owner's words, with Meta's own message (the token never appears in it). */
    private function explain(WhatsAppConnection $connection, ?Response $response): string
    {
        $code = (int) ($response?->json('error.code') ?? 0);
        $meta = (string) ($response?->json('error.message') ?? ($response ? 'HTTP '.$response->status() : ''));
        $meta = str_replace((string) $connection->access_token, '***', $meta);
        $hint = match (true) {
            in_array($code, self::TOKEN_ERRORS, true) => __('The access token is invalid or expired. Generate a new one with the expiry set to “Never”.'),
            in_array($code, [10, 200, 294], true) => __('The token is missing a permission. Generate it again with whatsapp_business_messaging, whatsapp_business_management and business_management.'),
            $code === 100 || $code === 803 => __('Meta could not find this ID with this token. Check the IDs and that the WhatsApp account is assigned to the system user.'),
            default => '',
        };

        return trim($hint.' '.($meta !== '' ? '(Meta: '.mb_substr($meta, 0, 140).')' : ''));
    }
}
