<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Base for every restaurant-owned record. BelongsToTenant adds a global `where tenant_id = <current tenant>`
 * scope and fills tenant_id on create; outside tenant context nothing can be inserted (tenant_id is NOT NULL).
 */
abstract class TenantModel extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];
}
