<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Platform owner (admin.<domain>). Separate table and guard from restaurant staff. */
class PlatformAdmin extends Authenticatable
{
    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'      => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }
}
