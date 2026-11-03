<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message of a PlatformConversation: from the person (in), from the team (out), or a note only the team sees.
 *
 * @property string $direction in | out | note
 * @property string $type
 * @property ?string $body
 * @property ?string $status
 * @property ?string $error
 */
class PlatformConversationMessage extends Model
{
    protected $guarded = ['id'];

    /**
     * Meta's own words for a refusal the team cannot fix, with what to do instead. Meta keeps a person from getting
     * too many marketing messages (error 131049) and asks not to try again within a day.
     */
    private const KNOWN_ERRORS = [
        'healthy ecosystem engagement' => 'Meta did not deliver it: it limits how many offers one person gets from businesses. It costs nothing. Try again tomorrow, or call them.',
    ];

    /** The failure as the team reads it: a known Meta refusal explained, anything else as Meta wrote it. */
    public function errorText(): ?string
    {
        foreach (self::KNOWN_ERRORS as $needle => $text) {
            if (str_contains((string) $this->error, $needle)) {
                return __($text);
            }
        }

        return $this->error;
    }

    /** @return BelongsTo<PlatformConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PlatformConversation::class, 'platform_conversation_id');
    }

    /** @return BelongsTo<PlatformAdmin, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }
}
