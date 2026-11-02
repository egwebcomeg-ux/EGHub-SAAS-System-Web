<?php

namespace App\Services\Crm;

use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use App\Models\ProspectEvent;
use App\Services\TenantProvisioner;
use Illuminate\Support\Str;

/**
 * The trial menu offer a sales person sends by hand from their own WhatsApp: the message opens ready on their phone and
 * they press send. Its link opens a chat with the EgHub number, where the place sends a photo of its menu. Marketing
 * numbers that never asked to hear from us cannot go through the EgHub number (Meta's opt-in rule), and each person has
 * a daily limit so their own number stays safe.
 */
final class WhatsAppOffer
{
    public const DAILY_LIMIT = 20;

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

    public function sentToday(PlatformAdmin $admin): int
    {
        return ProspectEvent::query()->where('admin_id', $admin->id)->where('type', 'whatsapp')->where('meta->offer', true)
            ->where('created_at', '>=', local_day_start(local_at()->toDateString()))->count();
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

    /** The place that got the offer and whose owner writes from this number. */
    public function placeWritingFrom(string $phone): ?Prospect
    {
        $mobile = normalize_phone($phone);
        if ($mobile === null) {
            return null;
        }

        return Prospect::query()->where(fn ($query) => $query->where('phone', $mobile)->orWhere('phone2', $mobile))
            ->whereHas('events', fn ($query) => $query->where('type', 'whatsapp')->where('meta->offer', true))->first();
    }
}
