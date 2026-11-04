<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\Entitlements;
use Illuminate\Support\Facades\Cache;
use Tests\TenantTestCase;

/** The cached rights come back from a real cache store as plain values, with the plan read again. */
class EntitlementsCacheTest extends TenantTestCase
{
    public function test_the_rights_read_back_from_a_serialising_store_keep_their_plan(): void
    {
        // the file store serialises like production's database store (cache.serializable_classes = false)
        config(['cache.default' => 'file']);
        Cache::flush();
        $a = $this->restaurant('cafe-a');

        $first = Entitlements::for($a);
        Entitlements::flush('');   // the memo only: the next read comes from the store
        $again = Entitlements::for($a);

        $this->assertInstanceOf(Plan::class, $again->plan);
        $this->assertSame($first->plan->code, $again->plan->code);
        $this->assertSame($first->allows('pos'), $again->allows('pos'));
        $this->actingAs($this->owner($a))->get($this->dashUrl('/'))->assertOk();
    }
}
