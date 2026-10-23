<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Tenant;
use Tests\TenantTestCase;

/**
 * The knowledge base ("الأسئلة والأجوبة"): the owner and managers write it, the call center, cashiers and waiters read
 * it from the feed, and only the public, active answers ever reach the guest menu and its FAQPage JSON-LD.
 */
class KnowledgeBaseTest extends TenantTestCase
{
    /** @param  array<string, mixed>  $attrs */
    private function article(Tenant $tenant, array $attrs = []): KbArticle
    {
        return $this->as($tenant, fn () => KbArticle::query()->create($attrs + [
            'question' => 'Do you deliver to Maadi?', 'answer' => 'Yes, 25 EGP.', 'visibility' => 'public', 'is_active' => true,
        ]));
    }

    public function test_the_owner_writes_edits_and_deletes_an_answer_with_audit_entries(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $owner = $this->owner($a);
        $category = $this->as($a, fn () => KbCategory::query()->create(['name' => 'Delivery']));

        $this->actingAs($owner)->get($this->dashUrl('/kb'))->assertOk()->assertSee('data-kb-empty', false);
        $this->actingAs($owner)->post($this->dashUrl('/kb'), [
            'question' => 'Is there parking?', 'answer' => 'Yes, behind the shop.', 'visibility' => 'internal',
            'category_id' => $category->id, 'tags' => 'parking', 'is_active' => '1',
        ])->assertRedirect(route('dash.kb'))->assertSessionHasNoErrors();

        $article = $this->as($a, fn () => KbArticle::query()->firstOrFail());
        $this->assertSame($category->id, $article->category_id);
        $this->assertSame($owner->id, $article->updated_by);
        $this->actingAs($owner)->get($this->dashUrl('/kb'))->assertOk()->assertSee('Is there parking?');

        $this->actingAs($owner)->post($this->dashUrl("/kb/{$article->id}"), [
            'question' => 'Where do I park?', 'answer' => 'Behind the shop.', 'visibility' => 'public', 'is_active' => '1',
        ])->assertRedirect(route('dash.kb'));
        $this->assertSame('public', $this->as($a, fn () => $article->fresh()->visibility));

        $this->actingAs($owner)->post($this->dashUrl("/kb/{$article->id}/delete"))->assertRedirect(route('dash.kb'));
        $this->assertSame(0, $this->as($a, fn () => KbArticle::query()->count()));

        $actions = $this->as($a, fn () => AuditLog::query()->where('action', 'like', 'kb.%')->orderBy('id')->pluck('action')->all());
        $this->assertSame(['kb.created', 'kb.updated', 'kb.deleted'], $actions);
    }

    public function test_a_category_of_another_restaurant_is_refused(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $b = $this->restaurant('cafe-b', 'full');
        $theirs = $this->as($b, fn () => KbCategory::query()->create(['name' => 'B only']));

        $this->actingAs($this->owner($a))->post($this->dashUrl('/kb'), [
            'question' => 'Q', 'answer' => 'A', 'visibility' => 'internal', 'category_id' => $theirs->id,
        ])->assertSessionHasErrors('category_id');
        $article = $this->article($b);
        $this->actingAs($this->owner($a))->get($this->dashUrl("/kb/{$article->id}/edit"))->assertNotFound();
    }

    public function test_a_cashier_reads_the_feed_but_cannot_manage_the_answers(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $article = $this->article($a);
        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');
        $kitchen = $this->staff($a, 'kitchen', 'k@cafe-a.test');

        $this->actingAs($cashier)->getJson($this->dashUrl('/kb/feed'))->assertOk()->assertJsonPath('articles.0.id', $article->id);
        $this->actingAs($cashier)->get($this->dashUrl('/kb'))->assertForbidden();
        $this->actingAs($cashier)->post($this->dashUrl('/kb'), ['question' => 'Q', 'answer' => 'A', 'visibility' => 'public'])->assertForbidden();
        $this->actingAs($cashier)->post($this->dashUrl("/kb/{$article->id}/delete"))->assertForbidden();
        $this->actingAs($kitchen)->getJson($this->dashUrl('/kb/feed'))->assertForbidden();

        $manager = $this->staff($a, 'manager', 'm@cafe-a.test');
        $this->actingAs($manager)->get($this->dashUrl('/kb'))->assertOk();
    }

    public function test_the_feed_has_internal_and_public_shown_answers_of_this_restaurant_only(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $b = $this->restaurant('cafe-b', 'full');
        $public = $this->article($a, ['question' => 'Opening hours?', 'answer' => "9 to 5\nsee https://example.com/hours.", 'tags' => 'hours']);
        $internal = $this->article($a, ['question' => 'Refund policy?', 'answer' => 'Manager approves.', 'visibility' => 'internal']);
        $this->article($a, ['question' => 'A draft', 'is_active' => false]);
        $hidden = $this->as($a, fn () => KbCategory::query()->create(['name' => 'Old', 'is_active' => false]));
        $this->article($a, ['question' => 'In a hidden category', 'category_id' => $hidden->id]);
        $this->article($b, ['question' => 'B secret answer', 'visibility' => 'internal']);

        $feed = $this->actingAs($this->staff($a, 'callcenter', 'cc@cafe-a.test'))->getJson($this->dashUrl('/kb/feed'))->assertOk()
            ->assertJsonStructure(['categories', 'articles' => [['id', 'category_id', 'category', 'question', 'answer', 'html', 'public', 'tags']]]);

        $this->assertEqualsCanonicalizing([$public->id, $internal->id], array_column($feed->json('articles'), 'id'));
        $feed->assertDontSee('B secret answer')->assertDontSee('A draft')->assertDontSee('In a hidden category');
        $first = collect($feed->json('articles'))->firstWhere('id', $public->id);
        $this->assertTrue($first['public']);
        $this->assertSame("9 to 5\nsee https://example.com/hours.", $first['answer']);
        $this->assertStringContainsString('<a href="https://example.com/hours" target="_blank" rel="noopener nofollow ugc">https://example.com/hours</a>.', $first['html']);
        $this->assertStringContainsString('9 to 5<br>', $first['html']);
    }

    public function test_the_feed_is_cached_per_restaurant_and_refreshed_when_an_answer_changes(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $article = $this->article($a);
        $owner = $this->owner($a);
        $this->actingAs($owner)->getJson($this->dashUrl('/kb/feed'))->assertJsonPath('articles.0.question', 'Do you deliver to Maadi?');

        $this->actingAs($owner)->post($this->dashUrl("/kb/{$article->id}"), [
            'question' => 'Do you deliver to Zamalek?', 'answer' => 'Yes.', 'visibility' => 'public', 'is_active' => '1',
        ]);
        $this->actingAs($owner)->getJson($this->dashUrl('/kb/feed'))->assertJsonPath('articles.0.question', 'Do you deliver to Zamalek?');

        $this->actingAs($owner)->post($this->dashUrl("/kb/{$article->id}/toggle"));
        $this->actingAs($owner)->getJson($this->dashUrl('/kb/feed'))->assertJsonCount(0, 'articles');
    }

    public function test_answers_are_escaped_and_only_http_links_become_links(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $this->article($a, ['answer' => '<script>alert(1)</script> <b>bold</b> javascript:alert(2) http://x.test/a?b=1&c=2']);

        $html = $this->get($this->pathUrl('cafe-a', 'about'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &lt;b&gt;bold&lt;/b&gt; javascript:alert(2)', $html);
        $this->assertStringContainsString('<a href="http://x.test/a?b=1&amp;c=2" target="_blank" rel="noopener nofollow ugc">', $html);
    }

    public function test_only_public_active_answers_show_on_the_about_page_and_its_json_ld(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $b = $this->restaurant('cafe-b', 'full');
        $this->article($a, ['question' => 'Opening hours?', 'question_en' => 'When do you open?', 'answer' => 'من ٩ لـ ٥', 'answer_en' => '']);
        $this->article($a, ['question' => 'Staff refund policy', 'visibility' => 'internal']);
        $this->article($a, ['question' => 'Unfinished draft', 'is_active' => false]);
        $this->article($b, ['question' => 'Only at B']);

        $page = $this->get($this->pathUrl('cafe-a', 'about'))->assertOk()
            ->assertSee('data-faq', false)->assertSee('Opening hours?')->assertSee('"@type":"FAQPage"', false)
            ->assertDontSee('Staff refund policy')->assertDontSee('Unfinished draft')->assertDontSee('Only at B');
        $this->assertSame(1, substr_count($page->getContent(), '"@type":"Question"'));

        // English: the English question, the Arabic answer when its English is empty
        $this->get($this->pathUrl('cafe-a', 'about?lang=en'))->assertOk()->assertSee('When do you open?')->assertSee('من ٩ لـ ٥');

        // a restaurant with internal answers only shows no FAQ, and the menu links to it only when there is one
        $c = $this->restaurant('cafe-c', 'full');
        $this->article($c, ['question' => 'Internal only', 'visibility' => 'internal']);
        $this->get($this->pathUrl('cafe-c', 'about'))->assertOk()->assertDontSee('data-faq', false)->assertDontSee('FAQPage');
        $this->get($this->pathUrl('cafe-c'))->assertOk()->assertDontSee('data-faq-link', false);
        $this->get($this->pathUrl('cafe-a'))->assertOk()->assertSee('data-faq-link', false);
    }

    public function test_the_free_plan_shows_the_page_locked_and_no_faq_on_the_menu(): void
    {
        $free = $this->restaurant('cafe-free', 'free');
        $this->article($free, ['question' => 'Public on free']);
        $owner = $this->owner($free);

        $this->actingAs($owner)->get($this->dashUrl('/kb'))->assertForbidden()->assertSee(__('Upgrade your plan'));
        $this->actingAs($owner)->getJson($this->dashUrl('/kb/feed'))->assertForbidden();
        $this->get($this->pathUrl('cafe-free', 'about'))->assertOk()->assertDontSee('Public on free')->assertDontSee('FAQPage');
        $this->get($this->pathUrl('cafe-free'))->assertOk()->assertDontSee('data-faq-link', false);
    }

    public function test_suggested_questions_are_added_once_as_drafts(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $owner = $this->owner($a);

        $this->actingAs($owner)->post($this->dashUrl('/kb/suggested'))->assertRedirect(route('dash.kb', ['show' => 'drafts']));
        $articles = $this->as($a, fn () => KbArticle::query()->get());
        $this->assertGreaterThanOrEqual(8, $articles->count());
        $this->assertTrue($articles->every(fn (KbArticle $x) => ! $x->is_active && $x->category_id !== null));
        $this->assertTrue($articles->contains(fn (KbArticle $x) => str_contains($x->answer, '[')));
        $this->assertTrue($articles->contains('visibility', 'internal'));

        $this->actingAs($owner)->getJson($this->dashUrl('/kb/feed'))->assertJsonCount(0, 'articles');
        $this->get($this->pathUrl('cafe-a', 'about'))->assertDontSee('data-faq', false);

        $this->actingAs($owner)->post($this->dashUrl('/kb/suggested'));
        $this->assertSame($articles->count(), $this->as($a, fn () => KbArticle::query()->count()));
        $this->assertSame(1, $this->as($a, fn () => AuditLog::query()->where('action', 'kb.suggested')->count()));
    }

    public function test_answers_move_up_and_down_within_their_category(): void
    {
        $a = $this->restaurant('cafe-a', 'full');
        $first = $this->article($a, ['question' => 'First', 'sort_order' => 1]);
        $second = $this->article($a, ['question' => 'Second', 'sort_order' => 2]);

        $this->actingAs($this->owner($a))->post($this->dashUrl("/kb/{$second->id}/move"), ['direction' => 'up']);
        $this->actingAs($this->owner($a))->getJson($this->dashUrl('/kb/feed'))
            ->assertJsonPath('articles.0.id', $second->id)->assertJsonPath('articles.1.id', $first->id);
    }

    public function test_the_call_center_pos_and_order_board_have_the_drawer(): void
    {
        $a = $this->restaurant('cafe-a', 'enterprise');
        $this->article($a);

        $this->actingAs($this->staff($a, 'callcenter', 'cc@cafe-a.test'))->get($this->dashUrl('/call-center'))->assertOk()
            ->assertSee('data-kb-drawer', false)->assertSee('data-kb-open', false)
            // no payment in the call center: the corporate voucher box (whose dialogs are POS-only) is not wired there
            ->assertDontSee('data-corp-box', false);
        $cashier = $this->staff($a, 'cashier', 'c@cafe-a.test');
        $this->actingAs($cashier)->get($this->dashUrl('/pos'))->assertOk()->assertSee('data-kb-drawer', false);
        $this->actingAs($cashier)->get($this->dashUrl('/orders'))->assertOk()->assertSee('data-kb-drawer', false);

        // without the plan feature the button is not there
        $pro = $this->restaurant('cafe-pro', 'pro');
        $this->actingAs($this->owner($pro))->get($this->dashUrl('/orders'))->assertOk()->assertDontSee('data-kb-drawer', false);
    }
}
