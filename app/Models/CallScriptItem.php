<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One piece of the sales call script the callers read on a lead's page: the opening line, the WhatsApp opener, the
 * "what EgHub is" pitch, a discovery question with tappable answers (each with the reply to give and a signal for
 * the offer advice, App\Services\Crm\OfferAdvisor), an objection with its reply, or the closing line. Edited by the
 * platform owner (admin › متابعة العملاء › السكريبت); the defaults come from Database\Seeders\CallScriptSeeder.
 * Text may use placeholders filled on the call (App\Services\Crm\CallScript::fill).
 */
class CallScriptItem extends Model
{
    protected $table = 'crm_script_items';

    protected $guarded = ['id'];

    /** kind => [arabic, english] in the order the call screen shows them */
    public const KINDS = [
        'opening' => ['بداية المكالمة', 'Opening'],
        'whatsapp' => ['رسالة الواتساب الأولى', 'First WhatsApp message'],
        'pitch' => ['EgHub في كلمتين', 'EgHub in a few words'],
        'question' => ['أسئلة نفهم بيها محله', 'Discovery questions'],
        'objection' => ['الاعتراضات والرد عليها', 'Objections and replies'],
        'closing' => ['قفلة المكالمة', 'Closing'],
    ];

    /**
     * What an answer tells us, for the offer advice. signal => [arabic, english]. The advisor reads these keys, so
     * they never change; the labels are only for the script editor.
     */
    public const SIGNALS = [
        '' => ['من غير إشارة', 'No signal'],
        'menu_only' => ['عايز منيو QR بس', 'Wants a QR menu only'],
        'wants_table_orders' => ['عايز الطلب من الترابيزة', 'Wants table ordering'],
        'wants_delivery' => ['عنده تيك أواي أو توصيل', 'Does takeaway or delivery'],
        'wants_loyalty' => ['عايز يرجّع زباينه', 'Wants returning guests'],
        'wants_reservations' => ['بيحجز ترابيزات', 'Takes reservations'],
        'needs_menu_help' => ['محتاج حد يدخّل المنيو', 'Needs help entering the menu'],
        'has_system' => ['عنده سيستم تاني', 'Already has a system'],
        'chain' => ['أكتر من فرع أو محتاج كاشير', 'Several branches or needs a POS'],
        'price_sensitive' => ['السعر فارق معاه', 'Price matters to them'],
        'ready_to_pay' => ['جاهز يشترك', 'Ready to subscribe'],
        'just_trying' => ['كان بيجرب', 'Was just trying'],
        'not_interested' => ['مش مهتم', 'Not interested'],
    ];

    protected function casts(): array
    {
        return ['options' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @param Builder<CallScriptItem> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<CallScriptItem> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public static function kindLabel(string $kind): string
    {
        return Lead::pick(self::KINDS[$kind] ?? [$kind, $kind]);
    }

    public static function signalLabel(string $signal): string
    {
        return Lead::pick(self::SIGNALS[$signal] ?? [$signal, $signal]);
    }

    /**
     * The answer options, cleaned.
     *
     * @return list<array{key: string, label: string, reply: string, signal: string}>
     */
    public function answerOptions(): array
    {
        return array_values(array_filter(array_map(fn ($o) => is_array($o) ? [
            'key' => (string) ($o['key'] ?? ''), 'label' => (string) ($o['label'] ?? ''),
            'reply' => (string) ($o['reply'] ?? ''), 'signal' => isset(self::SIGNALS[$o['signal'] ?? '']) ? (string) ($o['signal'] ?? '') : '',
        ] : null, (array) ($this->options ?? [])), fn ($o) => $o !== null && $o['key'] !== '' && $o['label'] !== ''));
    }
}
