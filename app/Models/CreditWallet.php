<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A restaurant's WhatsApp message credits (platform row, one per tenant). Changed only through App\Billing\Credits. */
class CreditWallet extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }
}
