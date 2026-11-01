<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A ready answer the team picks in «محادثات واتساب» (it fills the reply box, nothing is sent by itself). */
class PlatformCannedReply extends Model
{
    protected $guarded = ['id'];
}
