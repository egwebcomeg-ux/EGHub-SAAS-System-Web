<?php

namespace App\Models;

use Database\Factories\PlatformConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person writing to the EgHub number, or to its page on Messenger or Instagram (App\Services\WhatsApp\PlatformInbox).
 * Platform data, no tenant.
 *
 * @property string $channel whatsapp | messenger | instagram
 * @property string $phone the mobile on WhatsApp, the sender's id on the other two
 * @property ?string $name
 * @property string $status open | closed
 * @property int $unread
 */
class PlatformConversation extends Model
{
    /** @use HasFactory<PlatformConversationFactory> */
    use HasFactory;

    /** Meta allows a free-form reply for this long after the person's last message; after it only a template. */
    public const WINDOW_HOURS = 24;

    public const WHATSAPP = 'whatsapp';

    public const MESSENGER = 'messenger';

    public const INSTAGRAM = 'instagram';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'last_inbound_at' => 'datetime', 'bot_paused_until' => 'datetime', 'unread' => 'integer'];
    }

    /** @return HasMany<PlatformConversationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(PlatformConversationMessage::class);
    }

    /** @return BelongsTo<PlatformAdmin, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'assigned_to');
    }

    public function canReply(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(self::WINDOW_HOURS));
    }

    /** The team has this conversation: the automatic answer stays quiet. */
    public function botPaused(): bool
    {
        return $this->bot_paused_until !== null && $this->bot_paused_until->isFuture();
    }

    public function isWhatsApp(): bool
    {
        return $this->channel === self::WHATSAPP;
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            self::MESSENGER => __('Messenger'),
            self::INSTAGRAM => __('Instagram'),
            default => __('WhatsApp'),
        };
    }

    public function displayName(): string
    {
        if (filled($this->name)) {
            return (string) $this->name;
        }

        return $this->isWhatsApp() ? '+'.$this->phone : __('Customer on :channel', ['channel' => $this->channelLabel()]);
    }
}
