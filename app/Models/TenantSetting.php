<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;

/** One key/value setting of a restaurant (colors, WhatsApp, service %, ...). */
class TenantSetting extends TenantModel
{
    public $timestamps = false;
}
