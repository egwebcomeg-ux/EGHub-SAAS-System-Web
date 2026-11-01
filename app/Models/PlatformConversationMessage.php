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
