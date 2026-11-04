<?php

namespace App\Services\Crm;

use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformConversationMessage;
use App\Models\Prospect;
use App\Models\ProspectEvent;
use App\Services\TenantProvisioner;
use App\Services\WhatsApp\PlatformInbox;
use App\Services\WhatsApp\PlatformTemplates;
use Illuminate\Support\Str;

/**
 * The trial menu offer. A sales person sends it by hand from their own WhatsApp: the message opens ready on their phone
 * and they press send, and its link opens a chat with the EgHub number, where the place sends a photo of its menu. A
 * place that agreed on the phone to hear from us can get it from the EgHub number itself (an approved Marketing
 * template, Meta's opt-in rule): its answer lands in «المحادثات». Each person has a daily limit on their own number so it
 * stays safe.
 */
final class WhatsAppOffer
{
    public const DAILY_LIMIT = 20;

    /** The body of the template config whatsapp.templates.sales_offer, as submitted to Meta. */
    public const TEMPLATE_TEXT = "أهلًا، معاك {{1}} من EgHub، زي ما اتكلمنا في التليفون 👋\nهنعمل لـ {{2}} منيو QR كامل، تجربة ببلاش لمدة {{3}} يوم ومن غير أي التزام.\nابعت صورة المنيو هنا في رد على الرسالة دي، وإحنا نجهزه ونبعتلك اللينك تشوفه.\nومفيش عمولة على الطلبات.";

    /** A place is not offered again for this long. */
    public const RESEND_AFTER_DAYS = 30;

    /** Days until a place that got the offer comes back to the recommended calls. */
    public const FOLLOW_UP_DAYS = 3;

    public function canBeSentTo(Prospect $prospect): bool
    {
        return filled($prospect->phone) && $prospect->isOpen() && filled(config('whatsapp.number'));
    }

    public function sentRecently(Prospect $prospect): bool
    {
        return $prospect->events()->where('type', 'whatsapp')->where('meta->offer', true)
            ->where('created_at', '>=', now()->subDays(self::RESEND_AFTER_DAYS))->exists();
    }

    /** Offers sent today from this person's own number (the EgHub number has Meta's limits, not theirs). */
    public function sentToday(PlatformAdmin $admin): int
    {
        return ProspectEvent::query()->where('admin_id', $admin->id)->where('type', 'whatsapp')->where('meta->offer', true)
            ->where(fn ($query) => $query->whereNull('meta->via')->orWhere('meta->via', '!=', 'eghub'))
            ->where('created_at', '>=', local_day_start(local_at()->toDateString()))->count();
    }

    /** The EgHub number sends and Meta approved the offer's template. */
    public static function fromEgHubIsReady(): bool
    {
        return (bool) config('whatsapp.platform_sending') && filled(config('whatsapp.meta.phone_id')) && filled(config('whatsapp.meta.token'))
            && PlatformTemplates::isApproved((string) config('whatsapp.templates.sales_offer'));
    }

    /** Sends the offer from the EgHub number; the row says whether Meta took it. */
    public function sendFromEgHub(Prospect $prospect, PlatformAdmin $sender): PlatformConversationMessage
    {
        return app(PlatformInbox::class)->startWithTemplate(whatsapp_number((string) $prospect->phone), $prospect->name, 'sales_offer',
            [Str::before(trim($sender->name), ' '), $prospect->name, (string) TenantProvisioner::TRIAL_DAYS], self::TEMPLATE_TEXT, $sender);
    }

    /** The link that opens the place's WhatsApp chat with the message written. */
    public function url(Prospect $prospect, PlatformAdmin $sender): string
    {
        return 'https://wa.me/'.whatsapp_number((string) $prospect->phone).'?text='.rawurlencode($this->text($prospect, $sender));
    }

    public function text(Prospect $prospect, PlatformAdmin $sender): string
    {
        $freeItems = Plan::query()->where('code', 'free')->with('features')->first()?->entitlements()['limits']['menu_items'] ?? null;
        $reply = 'https://wa.me/'.whatsapp_number((string) config('whatsapp.number')).'?text='.rawurlencode('عايز منيو تجريبي لـ '.$prospect->name);

        return implode("\n", array_filter([
            'أهلاً، معاك '.Str::before(trim($sender->name), ' ').' من EgHub 👋',
            'شفنا '.$prospect->name.' وحابين نعملكم منيو QR كامل، تجربة ببلاش لمدة '.TenantProvisioner::TRIAL_DAYS.' يوم، من غير أي التزام.',
            'ابعتولنا صورة المنيو من هنا، وإحنا نجهزه ونبعتلكم اللينك تشوفوه:',
            $reply,
            $freeItems ? 'وبعد التجربة فيه باقة مجانية لحد '.$freeItems.' صنف، ومفيش عمولة على الطلبات.' : 'ومفيش عمولة على الطلبات.',
            'لو مش مهتمين قولولنا ومش هنبعت تاني 🙏',
        ]));
    }

    /** The place that got the offer, or came from one of our ads, and whose owner writes from this number. */
    public function placeWritingFrom(string $phone): ?Prospect
    {
        $mobile = normalize_phone($phone);
        if ($mobile === null) {
            return null;
        }

        return Prospect::query()->where(fn ($query) => $query->where('phone', $mobile)->orWhere('phone2', $mobile))
            ->where(fn ($query) => $query->where('source', 'ad')
                ->orWhereHas('events', fn ($events) => $events->where('type', 'whatsapp')->where('meta->offer', true)))->first();
    }

    public function gotTheOffer(Prospect $prospect): bool
    {
        return $prospect->events()->where('type', 'whatsapp')->where('meta->offer', true)->exists();
    }
}
