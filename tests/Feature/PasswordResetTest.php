<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Notifications\ResetPasswordLink;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

class PasswordResetTest extends TenantTestCase
{
    public function test_staff_reset_their_password_with_the_emailed_link(): void
    {
        Notification::fake();
        $owner = $this->owner($this->restaurant('cafe-a'));

        $this->post($this->dashUrl('/forgot-password'), ['email' => strtoupper($owner->email)])->assertSessionHas('ok');
        $url = null;
        Notification::assertSentTo($owner, ResetPasswordLink::class, function (ResetPasswordLink $n) use (&$url) {
            $url = $n->url;

            return true;
        });
        $this->assertStringContainsString('app.', $url);
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->get($this->dashUrl("/reset-password/$token?email={$owner->email}"))->assertOk();
        $this->post($this->dashUrl('/reset-password'), [
            'token' => $token, 'email' => $owner->email, 'password' => 'New-pass-2026', 'password_confirmation' => 'New-pass-2026',
        ])->assertRedirect($this->dashUrl('/login'));
        $this->assertTrue(Hash::check('New-pass-2026', $owner->fresh()->password));

        // the link works once
        $this->post($this->dashUrl('/reset-password'), [
            'token' => $token, 'email' => $owner->email, 'password' => 'Other-pass-2026', 'password_confirmation' => 'Other-pass-2026',
        ])->assertSessionHasErrors('email');
    }

    public function test_unknown_email_gets_the_same_answer_and_admins_use_their_own_broker(): void
    {
        Notification::fake();
        $owner = $this->owner($this->restaurant('cafe-a'));
        $this->post($this->dashUrl('/forgot-password'), ['email' => 'nobody@nowhere.test'])->assertSessionHas('ok');

        // a platform admin with the same email as a staff member: each side only resets its own account
        $admin = PlatformAdmin::query()->create(['name' => 'Admin', 'email' => $owner->email, 'password' => 'Secret-pass-123']);
        $this->post($this->adminUrl('/forgot-password'), ['email' => $owner->email])->assertSessionHas('ok');
        Notification::assertSentTo($admin, ResetPasswordLink::class, fn (ResetPasswordLink $n) => str_contains($n->url, 'admin.'));
        Notification::assertNotSentTo($owner, ResetPasswordLink::class);
    }
}
