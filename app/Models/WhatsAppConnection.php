<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;

/**
 * The restaurant's own WhatsApp Business number (Cloud API, credentials the owner pasted). The token and the app
 * secret are encrypted at rest, hidden from arrays/JSON and never shown back in full (maskedToken()).
 *
 * @property string $waba_id
 * @property string $phone_number_id
 * @property string $access_token
 * @property ?string $app_secret
 * @property string $webhook_key
 * @property string $verify_token
 * @property string $status draft | connected | failed
 * @property ?array<string, array<string, string>> $templates template name => language => pending|approved|rejected|paused|disabled|error
 */
class WhatsAppConnection extends TenantModel
{
    public const STATUSES = ['draft', 'connected', 'failed'];

    /** Meta template states we show (anything else shows as pending). */
    public const TEMPLATE_STATES = ['pending', 'approved', 'rejected', 'paused', 'disabled', 'error'];

    protected $table = 'whatsapp_connections';

    protected $hidden = ['access_token', 'app_secret', 'verify_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'templates' => 'array',
            'checked_at' => 'datetime',
            'subscribed_at' => 'datetime',
            'webhook_verified_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'templates_checked_at' => 'datetime',
        ];
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    /** The last characters of the token only: enough to recognise it, never enough to use it. */
    public function maskedToken(): string
    {
        $token = (string) $this->access_token;

        return $token === '' ? '' : '••••••••'.mb_substr($token, -4);
    }

    public function templateStatus(string $name, string $language): string
    {
        return (string) ($this->templates[$name][$language] ?? 'missing');
    }

    public function templateApproved(string $name, string $language): bool
    {
        return $this->templateStatus($name, $language) === 'approved';
    }
}
