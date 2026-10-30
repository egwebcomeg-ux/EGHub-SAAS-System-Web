<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WhatsApp message the restaurant sends by itself when something happens to a guest: the first counted order
 * (welcome), a long absence (winback) or the birthday (App\Services\Marketing\Automations).
 */
class CampaignAutomation extends TenantModel
{
    /** kind => [arabic, english, cooldown in days per guest (null = once ever)] */
    public const KINDS = [
        'welcome' => ['ترحيب بعد أول طلب', 'Welcome after the first order', null],
        'winback' => ['وحشتنا (مزاروش من فترة)', 'We miss you (no visit for a while)', 90],
        'birthday' => ['عيد ميلاد', 'Birthday', 300],
    ];

    /** Default message per kind and language ({name}, {coupon}, {restaurant}, {link}). */
    public const DEFAULT_MESSAGES = [
        'welcome' => [
            'ar' => 'أهلاً {name} 👋 شكراً إنك طلبت من {restaurant}! نورتنا، ومستنيينك المرة الجاية. {coupon}',
            'en' => 'Hi {name} 👋 thank you for ordering from {restaurant}! We hope to see you again soon. {coupon}',
        ],
        'winback' => [
            'ar' => 'وحشتنا يا {name} 💛 بقالك فترة مجيتش {restaurant}. تعالى شوفنا وخد كوبون {coupon} على طلبك الجاي: {link}',
            'en' => 'We miss you {name} 💛 it has been a while since your last visit to {restaurant}. Come back with coupon {coupon}: {link}',
        ],
        'birthday' => [
            'ar' => 'كل سنة وإنت طيب يا {name} 🎂 {restaurant} بيهنيك بعيد ميلادك وعندك هدية: كوبون {coupon} 🎁 {link}',
            'en' => 'Happy birthday {name} 🎂 a gift from {restaurant}: coupon {coupon} 🎁 {link}',
        ],
    ];

    /** Default send window per kind: [from hour, to hour] (birthday greetings go out in the morning). */
    public const DEFAULT_WINDOW = ['welcome' => [10, 22], 'winback' => [11, 21], 'birthday' => [9, 13]];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'days' => 'integer', 'send_from' => 'integer', 'send_to' => 'integer'];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public static function kindLabel(string $kind): string
    {
        $l = self::KINDS[$kind] ?? [$kind, $kind];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    /** Days a guest must wait before getting this automation again; null = never again. */
    public function cooldownDays(): ?int
    {
        return self::KINDS[$this->kind][2] ?? null;
    }

    /** Whether the local hour of $at falls inside the send window. */
    public function inWindow(\DateTimeInterface $at): bool
    {
        $hour = (int) local_at($at)->format('G');

        return $hour >= $this->send_from && $hour < $this->send_to;
    }
}
