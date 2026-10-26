<?php

namespace Tests\Feature;

use App\Models\CallScriptItem;
use App\Models\Lead;
use App\Models\PlatformAdmin;
use App\Services\Crm\CallScript;
use Database\Seeders\CallScriptSeeder;
use Tests\TenantTestCase;

/** The call script: seeded once without overwriting edits, edited by the platform owner only, read on the call screen. */
class CrmScriptTest extends TenantTestCase
{
    private function admin(string $role = 'owner'): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['name' => ucfirst($role), 'email' => "$role@egweb.test", 'password' => 'Secret-pass-123', 'role' => $role]);
    }

    public function test_the_seeder_adds_the_defaults_once_and_keeps_edits_and_hidden_items(): void
    {
        $this->seed(CallScriptSeeder::class);
        $count = CallScriptItem::query()->count();
        $this->assertGreaterThan(5, $count);
        CallScriptItem::query()->where('key', 'opening')->update(['body' => 'My own opening']);
        CallScriptItem::query()->where('key', 'obj_busy')->update(['is_active' => false]);

        $this->seed(CallScriptSeeder::class);

        $this->assertSame($count, CallScriptItem::query()->count());
        $this->assertSame('My own opening', CallScriptItem::query()->where('key', 'opening')->value('body'));
        $this->assertFalse((bool) CallScriptItem::query()->where('key', 'obj_busy')->value('is_active'));
    }

    public function test_the_owner_edits_a_question_and_its_answers_keep_their_keys(): void
    {
        CallScript::installDefaults();
        $question = CallScriptItem::query()->where('key', 'q_why')->firstOrFail();
        $options = $question->answerOptions();
        $options[0]['label'] = 'منيو بس';
        $options[] = ['key' => '', 'label' => 'إجابة جديدة', 'reply' => 'رد جديد', 'signal' => 'wants_loyalty'];
        $options[] = ['key' => '', 'label' => '', 'reply' => 'ignored', 'signal' => ''];

        $this->actingAs($this->admin(), 'admin')->post($this->adminUrl('/crm/script/'.$question->id), [
            'title' => 'ليه سجل', 'body' => 'سجلت ليه؟', 'is_active' => '1', 'options' => $options,
        ])->assertRedirect();

        $saved = $question->fresh()->answerOptions();
        $this->assertSame('menu', $saved[0]['key']);
        $this->assertSame('منيو بس', $saved[0]['label']);
        $this->assertCount(count($options) - 1, $saved);
        $this->assertSame('wants_loyalty', end($saved)['signal']);
        $this->assertSame('سجلت ليه؟', $question->fresh()->body);
    }

    public function test_the_edited_script_is_what_the_caller_reads(): void
    {
        CallScript::installDefaults();
        $owner = $this->admin();
        $this->actingAs($owner, 'admin')->post($this->adminUrl('/crm/script'), [
            'kind' => 'objection', 'title' => '«عندي منيو ورق»', 'body' => 'الورق بيبوظ يا {owner}',
        ])->assertRedirect();
        $this->actingAs($owner, 'admin')->post($this->adminUrl('/crm/script/'.CallScriptItem::query()->where('key', 'obj_busy')->value('id').'/delete'))->assertRedirect();
        $this->assertTrue(CallScriptItem::query()->where('key', 'obj_busy')->exists());

        $lead = Lead::for($this->restaurant('cafe-a'));
        $this->actingAs($owner, 'admin')->get($this->adminUrl('/crm/leads/'.$lead->id))->assertOk()
            ->assertSee('«عندي منيو ورق»')->assertSee('الورق بيبوظ يا Owner')->assertDontSee('data-objection="obj_busy"', false);
    }

    public function test_only_the_platform_owner_edits_the_script(): void
    {
        CallScript::installDefaults();
        $manager = $this->admin('manager');
        $item = CallScriptItem::query()->firstOrFail();

        $this->actingAs($manager, 'admin')->get($this->adminUrl('/crm/script'))->assertForbidden();
        $this->actingAs($manager, 'admin')->post($this->adminUrl('/crm/script/'.$item->id), ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('/crm/script'))->assertOk()->assertSee('data-script-item="opening"', false);
    }
}
