<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

/** The "Powered by EgHub" badge on the public menu is removed by the `hide_badge` plan feature. */
class HideBadgeTest extends TenantTestCase
{
    public function test_badge_shows_without_the_feature_and_is_hidden_with_it(): void
    {
        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->item($pro, 'Latte');
        $this->get($this->subUrl('cafe-pro'))->assertOk()->assertSee('مقدم من');

        $business = $this->restaurant('cafe-biz', 'business');
        $this->item($business, 'Latte');
        $this->get($this->subUrl('cafe-biz'))->assertOk()->assertDontSee('مقدم من');
    }
}
