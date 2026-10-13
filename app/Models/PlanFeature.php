<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A feature switch (0/1) or a limit (n, null = unlimited) of a plan. */
class PlanFeature extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
