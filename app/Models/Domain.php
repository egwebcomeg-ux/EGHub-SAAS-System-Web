<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/** A restaurant's own domain ("menu.cafe.com", Business plan). Served only once verified. */
class Domain extends BaseDomain
{
    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
