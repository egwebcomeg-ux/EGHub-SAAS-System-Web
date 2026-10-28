<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

/** Validation errors reach the owner in Arabic, never as raw keys like validation.regex (production falls back to ar). */
class ArabicValidationMessagesTest extends TenantTestCase
{
    public function test_a_wrong_staff_mobile_number_gets_a_clear_arabic_message(): void
    {
        config(['app.fallback_locale' => 'ar']);
        $tenant = $this->restaurant('cafe-team');
        $owner = $this->owner($tenant);

        $this->actingAs($owner)->from($this->dashUrl('/users'))->post($this->dashUrl('/users'), [
            'name' => 'Ahmed', 'phone' => '0123', 'role' => 'cashier', 'password' => 'Secret-pass-123',
        ])->assertSessionHasErrors(['phone' => 'اكتب رقم موبايل مصري: 11 رقم بيبدأ بـ 010 أو 011 أو 012 أو 015.']);
    }

    public function test_every_rule_message_is_translated(): void
    {
        config(['app.fallback_locale' => 'ar']);
        app()->setLocale('ar');

        foreach (['required', 'email', 'unique', 'regex', 'confirmed', 'max.string', 'min.string', 'in', 'numeric', 'image'] as $rule) {
            $this->assertStringNotContainsString('validation.', __("validation.$rule", ['attribute' => 'x', 'max' => 1, 'min' => 1]), $rule);
        }
        $this->assertStringNotContainsString('pagination.', __('pagination.next'));
    }
}
