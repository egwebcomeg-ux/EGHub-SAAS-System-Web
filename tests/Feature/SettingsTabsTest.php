<?php

namespace Tests\Feature;

use App\Services\Settings;
use Tests\TenantTestCase;

/** The settings page in tabs: one form, the open tab kept by ?tab=, saving from a tab keeps every other setting. */
class SettingsTabsTest extends TenantTestCase
{
    private const FORM = [
        'brand_name' => 'Cafe A', 'currency' => 'ج.م', 'currency_en' => 'EGP', 'color_primary' => '#F26522',
        'color_secondary' => '#0A2540', 'color_bg' => '#FFFFFF', 'font' => 'Cairo', 'default_locale' => 'ar',
    ];

    public function test_the_tab_in_the_address_opens_and_every_section_is_in_one_form(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);

        $html = $this->actingAs($owner)->get($this->dashUrl('/settings?tab=orders'))->assertOk()
            ->assertSee('name="settings_tab" value="orders"', false)->getContent();
        $this->assertMatchesRegularExpression('/data-settings-panel="orders"\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-settings-panel="identity"\s+hidden/', $html);
        foreach (['identity', 'menu', 'orders', 'messages', 'languages', 'account'] as $tab) {
            $this->assertStringContainsString('data-settings-tab="'.$tab.'"', $html);
        }
        // the ordering section (linked from the checklist as #ordering) lives in the orders tab, the WhatsApp one in messages
        $this->assertMatchesRegularExpression('/data-settings-panel="orders".*?id="ordering".*?data-settings-panel="messages".*?id="whatsapp"/s', $html);
        // one form: every tab's fields are sent with any save
        $this->assertSame(1, substr_count($html, 'action="'.route('dash.settings.update').'"'));

        // an unknown tab falls back to the first one
        $this->actingAs($owner)->get($this->dashUrl('/settings?tab=nope'))->assertOk()->assertSee('name="settings_tab" value="identity"', false);
    }

    public function test_saving_from_a_tab_comes_back_to_it_and_keeps_the_other_tabs_settings(): void
    {
        $a = $this->restaurant('cafe-a');
        $owner = $this->owner($a);
        $this->as($a, fn () => Settings::put(['english_enabled' => '1', 'ordering_enabled' => '1', 'tagline' => 'Coffee', 'order_type_prompt' => '1']));

        // the messages tab: the form still carries the other tabs' values (as the browser sends them)
        $this->actingAs($owner)->post($this->dashUrl('/settings'), self::FORM + [
            'settings_tab' => 'messages', 'tagline' => 'Coffee', 'english_enabled' => '1', 'ordering_enabled' => '1',
            'dine_in_sent' => '1', 'order_type_prompt' => '1', 'wa_sender_name' => 'Cafe A team',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dash.settings', ['tab' => 'messages']));

        $this->as($a, function () {
            $this->assertSame('Coffee', Settings::get('tagline'));
            $this->assertSame('1', Settings::get('english_enabled'));
            $this->assertSame('1', Settings::get('ordering_enabled'));
            $this->assertSame('1', Settings::get('order_type_prompt'));
        });

        // without a tab (older clients) the save goes back where it came from, as before
        $this->actingAs($owner)->from($this->dashUrl('/settings'))->post($this->dashUrl('/settings'), self::FORM + ['settings_tab' => 'nope'])
            ->assertRedirect($this->dashUrl('/settings'));
        // a failed save reopens the tab it was sent from
        $this->actingAs($owner)->from($this->dashUrl('/settings'))->post($this->dashUrl('/settings'), array_merge(self::FORM, ['settings_tab' => 'languages', 'currency' => '']))
            ->assertSessionHasErrors('currency');
        $this->actingAs($owner)->get($this->dashUrl('/settings'))->assertSee('name="settings_tab" value="languages"', false);
    }
}
