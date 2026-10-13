<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\Lifecycle;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Notifications\PaymentClaimed;
use App\Notifications\PaymentConfirmed;
use App\Notifications\RestaurantSuspended;
use App\Notifications\SubscriptionEndingSoon;
use App\Notifications\SubscriptionPastDue;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

class NotificationsTest extends TenantTestCase
{
    public function test_owners_are_emailed_through_the_subscription_lifecycle(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $owner = $this->owner($a);
        $cashier = $this->staff($a, 'cashier', 'cashier@a.test');
        $otherOwner = $this->owner($this->restaurant('cafe-b', 'pro', trial: true));
        $lifecycle = app(Lifecycle::class);

        $this->travel(12)->days();
        $lifecycle->run();
        Notification::assertSentTo($owner, SubscriptionEndingSoon::class);
        Notification::assertNotSentTo($cashier, SubscriptionEndingSoon::class);

        $this->travel(3)->days();
        $lifecycle->run();
        Notification::assertSentTo($owner, SubscriptionPastDue::class);

        $this->travel(6)->days();
        $lifecycle->run();
        Notification::assertSentTo($owner, RestaurantSuspended::class, fn (RestaurantSuspended $n) => $n->tenant->id === 'cafe-a');
        // each restaurant's emails go to its own owners only
        Notification::assertSentTo($otherOwner, RestaurantSuspended::class, fn (RestaurantSuspended $n) => $n->tenant->id === 'cafe-b');
    }

    public function test_payment_claim_alerts_admins_and_confirmation_sends_a_receipt(): void
    {
        Notification::fake();
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $owner = $this->owner($a);
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => 'admin@egmenu.test', 'password' => 'Secret-pass-123']);
        $billing = app(Billing::class);

        $invoice = $billing->invoiceForPlan($a, Plan::query()->where('code', 'basic')->first(), 1);
        $billing->claimManualPayment($invoice, 'instapay', 'TX-1', null);
        Notification::assertSentTo($admin, PaymentClaimed::class);

        $this->assertTrue($billing->markPaid($invoice, 'manual', 'TX-1', $invoice->total, 'confirm-1'));
        $this->assertFalse($billing->markPaid($invoice, 'manual', 'TX-1', $invoice->total, 'confirm-1'));
        Notification::assertSentToTimes($owner, PaymentConfirmed::class, 1);
    }

    public function test_emails_are_arabic_right_to_left_by_default(): void
    {
        $a = $this->restaurant('cafe-a', 'pro', trial: true);
        $owner = $this->owner($a);
        $sub = $a->subscription()->first();

        $html = (string) app()->call(fn () => (new SubscriptionEndingSoon($sub))->toMail($owner)->render());
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('التجربة المجانية', $html);
        $this->assertSame('ar', $owner->preferredLocale());
    }
}
