<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;

/** One copy of an order the cashier app printed by itself (receipt, kitchen:<station>): never printed twice. */
class OrderPrint extends TenantModel {}
