<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Feedback;
use App\Notifications\LowRatingReceived;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

/** A rating of 1 or 2 reaches the restaurant's own managers while it is fresh, and only theirs. */
class LowRatingAlertTest extends TenantTestCase
{
    private function rate(string $slug, int $stars): void
    {
        $this->postJson($this->subUrl($slug, 'feedback'), ['rating' => $stars, 'comment' => 'بارد'])->assertOk();
    }

    public function test_a_low_rating_emails_this_restaurants_managers_only(): void
    {
        Notification::fake();
        $tenant = $this->restaurant('cafe-a', 'business');
        $other = $this->restaurant('cafe-b', 'business');
        $owner = $this->owner($tenant);
        $otherOwner = $this->owner($other);
        $cashier = $this->staff($tenant, 'cashier', 'cashier@cafe.test');

        $this->rate('cafe-a', 2);

        Notification::assertSentTo($owner, LowRatingReceived::class, fn (LowRatingReceived $n) => $n->rating === 2 && $n->comment === 'بارد');
        Notification::assertNotSentTo($otherOwner, LowRatingReceived::class);
        Notification::assertNotSentTo($cashier, LowRatingReceived::class);
    }

    public function test_a_good_rating_sends_nothing(): void
    {
        Notification::fake();
        $tenant = $this->restaurant('cafe-a', 'business');
        $this->owner($tenant);

        $this->rate('cafe-a', 3);

        Notification::assertNothingSent();
    }

    public function test_a_manager_of_another_branch_is_not_told(): void
    {
        Notification::fake();
        $tenant = $this->restaurant('cafe-a', 'business');
        $other = $this->as($tenant, fn () => Branch::query()->create(['name' => 'Other', 'slug' => 'other'])->id);
        $manager = $this->staff($tenant, 'manager', 'manager@cafe.test');
        $manager->forceFill(['branch_id' => $other])->save();

        $this->rate('cafe-a', 1);

        Notification::assertNotSentTo($manager, LowRatingReceived::class);
    }

    public function test_the_bell_shows_it_to_who_may_see_reports_in_that_restaurant(): void
    {
        $tenant = $this->restaurant('cafe-a', 'business');
        $other = $this->restaurant('cafe-b', 'business');
        $this->as($other, fn () => Feedback::query()->create(['branch_id' => Branch::query()->value('id'), 'rating' => 1, 'comment' => 'غيرنا']));
        $this->as($tenant, fn () => Feedback::query()->create(['branch_id' => Branch::query()->value('id'), 'rating' => 2, 'comment' => 'بارد']));

        $kinds = fn () => collect($this->getJson($this->dashUrl('/notifications'))->assertOk()->json('items'))->where('kind', 'rating');

        $this->actingAs($this->owner($tenant));
        $this->assertSame(['بارد'], $kinds()->pluck('detail')->all());

        $this->actingAs($this->staff($tenant, 'kitchen', 'kitchen@cafe.test'));
        $this->assertCount(0, $kinds());
    }

    public function test_the_sign_in_sheet_shows_whatsapp_before_the_paid_mobile_code(): void
    {
        config(['whatsapp.guest_sign_in' => true, 'whatsapp.number' => '201000000000', 'whatsapp.app_secret' => 'secret', 'sms.driver' => 'log']);
        $this->restaurant('cafe-a', 'business');

        $page = $this->get($this->subUrl('cafe-a'))->assertOk()->getContent();

        $whatsapp = strpos($page, 'data-wa-sign-in');
        $this->assertNotFalse($whatsapp);
        $code = strpos($page, 'data-phone-code ');
        if ($code !== false) {
            $this->assertLessThan($code, $whatsapp);
        }
    }
}
