<?php

namespace App\Models;

use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** Platform owner (admin.<domain>). Separate table and guard from restaurant staff. */
class PlatformAdmin extends Authenticatable
{
    protected $guarded = ['id'];

    use Notifiable;

    protected $hidden = ['password', 'remember_token'];

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordLink(route('admin.password.reset', ['token' => $token, 'email' => $this->email])));
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }
}
