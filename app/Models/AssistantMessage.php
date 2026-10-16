<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One question to the website assistant and its answer (platform level, no personal data). */
class AssistantMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['handoff' => 'boolean', 'created_at' => 'datetime'];
    }
}
