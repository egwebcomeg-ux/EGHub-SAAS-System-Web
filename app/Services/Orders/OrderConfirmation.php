<?php

namespace App\Services\Orders;

use App\Billing\Credits;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\Notify\GuestNotifier;
use App\Services\Settings;
use App\Services\WhatsApp\OwnNumber;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "Confirm your order" on WhatsApp for a cash-on-delivery delivery order the guest placed from the menu link
 * (setting wa_order_confirm, WhatsApp messages in the plan, one credit like every message). The guest taps
 * «تأكيد الطلب», «تعديل الطلب» or «إلغاء الطلب» (quick-reply buttons, or the signed confirmation page for a provider
 * without buttons) and gets a free reply inside the 24-hour window the tap opened.
 *
 * Only a badge on the order: the kitchen starts at once and never waits. "Cancel" asks the restaurant to cancel
 * (badge «العميل طالب إلغاء»); it never cancels the order by itself, because the food may already be cooking even
 * while the order still shows as new. Staff cancel it with the board's own button after a call.
 *
 * orders.confirm_status: null (not asked) | pending | confirmed | edit | cancel_requested; "no reply" is a pending
 * order older than the setting's minutes, worked out when read (no scheduled job).
 */
final class OrderConfirmation
{
    /** Orders the guest placed alone (no call-center agent already on the phone with them). */
    public const SOURCES = ['online', 'whatsapp'];

    /** button action => confirm_status */
    public const ACTIONS = ['confirm' => 'confirmed', 'edit' => 'edit', 'cancel' => 'cancel_requested'];

    /** state => [ar, en, tone] */
    public const STATES = [
        'pending' => ['مستني تأكيد العميل', 'Awaiting the guest', 'amber'],
        'confirmed' => ['العميل أكّد', 'Confirmed by the guest', 'emerald'],
        'phone' => ['اتأكد بالتليفون', 'Confirmed by phone', 'emerald'],
        'edit' => ['العميل عايز يعدّل', 'Guest wants a change', 'sky'],
        'cancel_requested' => ['العميل طالب إلغاء', 'Guest asks to cancel', 'red'],
        'no_reply' => ['مأكّدش', 'No reply', 'slate'],
    ];

    /** States staff still have to look at (the board's filter). */
    public const OPEN_STATES = ['pending', 'no_reply', 'edit', 'cancel_requested'];

    public const DEFAULT_MINUTES = 10;

    public const MAX_MINUTES = 120;

    /** Lines named in the message before "+N more". */
    public const MAX_ITEMS = 3;

    private const PAYLOAD = '/^oc:(confirm|edit|cancel):([a-f0-9]{32})$/';

    public function __construct(private WhatsApp $whatsapp, private Credits $credits) {}

    public static function enabled(): bool
    {
        return Entitlements::current()->allows('whatsapp_notifications') && Settings::get('wa_order_confirm') === '1';
    }

    /** Delivery, placed by the guest, not paid (cash at the door), still open, with a mobile number. */
    public static function qualifies(Order $order): bool
    {
        return $order->type === 'delivery' && in_array($order->source, self::SOURCES, true)
            && $order->payment_status !== 'paid' && ! in_array($order->status, ['served', 'cancelled'], true)
            && normalize_phone((string) $order->customer_phone) !== null;
    }

    /** Minutes without an answer before the order shows «مأكّدش». */
    public static function minutes(): int
    {
        $minutes = (int) Settings::get('wa_order_confirm_minutes');

        return $minutes >= 1 ? min($minutes, self::MAX_MINUTES) : self::DEFAULT_MINUTES;
    }

    /**
     * Asks the guest once, right after the order is placed. Never throws: a message problem never touches the order.
     * Returns whether the message was queued.
     */
    public function request(Order $order): bool
    {
        try {
            if ($order->confirm_status !== null || ! self::qualifies($order) || ! self::enabled()) {
                return false;
            }
            // no credit: the restaurant calls as before, no badge waits for a message that never left (a message from
            // the restaurant's own number needs no credit: Meta bills its own account)
            if (! OwnNumber::covers('order_confirm') && $this->credits->balance(tenant()) < 1) {
                return false;
            }
            if ($order->customer_id && Customer::query()->whereKey($order->customer_id)->where('whatsapp_opt_out', true)->exists()) {
                return false;
            }
            $token = random_token(16);
            $claimed = Order::query()->whereKey($order->id)->whereNull('confirm_status')
                ->update(['confirm_token' => $token, 'confirm_status' => 'pending', 'confirm_sent_at' => now()]);
            if ($claimed !== 1) {
                return false;
            }
            $order->refresh()->loadMissing(['items', 'branch']);
            $message = $this->whatsapp->record((string) $order->customer_phone, 'order_confirm', $this->values($order),
                $order->customer_id, null, null, $this->payloads($order), $order->confirmUrl());
            if (! $message) {
                $order->update(['confirm_status' => null]);

                return false;
            }
            $order->update(['confirm_message_id' => $message->id]);
            SendWhatsAppMessage::dispatch($message->id)->afterCommit();

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The template values: one line each (Meta refuses new lines in a parameter), the items cut after a few.
     *
     * @return array<string,string>
     */
    public function values(Order $order): array
    {
        $en = WhatsApp::locale() === 'en';
        $lines = $order->items->take(self::MAX_ITEMS)->map(fn ($i) => self::oneLine($i->name).' ×'.$i->quantity)->implode($en ? ', ' : '، ');
        $more = $order->items->count() - self::MAX_ITEMS;
        if ($more > 0) {
            $lines .= ' '.($en ? "+$more more" : "+$more أصناف");
        }
        $address = (array) ($order->delivery_address ?? []);
        $where = trim(($address['zone'] ?? '').' - '.($address['text'] ?? ''), ' -');

        return [
            'name' => self::oneLine((string) $order->customer_name),
            'number' => (string) $order->number,
            'restaurant' => self::oneLine(GuestNotifier::senderName()),
            'items' => mb_strimwidth($lines, 0, 160, '…'),
            'total' => money($order->total).' '.($en ? 'EGP' : 'جنيه'),
            'address' => mb_strimwidth(self::oneLine($where) ?: '—', 0, 160, '…'),
        ];
    }

    /**
     * Each button's payload: the action and the order's random token (never its id or number).
     *
     * @return array<string,string>
     */
    public function payloads(Order $order): array
    {
        $out = [];
        foreach (array_keys(self::ACTIONS) as $action) {
            $out[$action] = 'oc:'.$action.':'.$order->confirm_token;
        }

        return $out;
    }

    /**
     * The state shown on the order: null when the guest was never asked.
     */
    public static function state(Order $order): ?string
    {
        return match ($order->confirm_status) {
            null => null,
            'confirmed' => $order->confirm_via === 'phone' ? 'phone' : 'confirmed',
            'pending' => $order->confirm_sent_at && $order->confirm_sent_at->lte(now()->subMinutes(self::minutes())) ? 'no_reply' : 'pending',
            default => $order->confirm_status,
        };
    }

    public static function label(string $state): string
    {
        $l = self::STATES[$state] ?? [$state, $state];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    /**
     * What the dashboard screens show: the state, its label and tone, and whether staff can still mark it confirmed
     * after a call.
     *
     * @return array{state:string, label:string, tone:string, open:bool, can_phone:bool}|null
     */
    public static function view(Order $order): ?array
    {
        $state = self::state($order);
        if ($state === null) {
            return null;
        }
        $active = ! in_array($order->status, ['served', 'cancelled'], true);

        return [
            'state' => $state, 'label' => self::label($state), 'tone' => self::STATES[$state][2] ?? 'slate',
            'open' => $active && in_array($state, self::OPEN_STATES, true),
            'can_phone' => $active && ! in_array($state, ['confirmed', 'phone'], true),
        ];
    }

    /**
     * Applies the guest's answer once. Returns applied | repeat (already answered) | closed (finished, cancelled or
     * never asked) | invalid.
     */
    public function answer(Order $order, string $action, string $via): string
    {
        if (! isset(self::ACTIONS[$action])) {
            return 'invalid';
        }
        if ($order->confirm_status === null || in_array($order->status, ['served', 'cancelled'], true)) {
            return 'closed';
        }
        if ($order->confirm_status !== 'pending') {
            return 'repeat';
        }
        $claimed = Order::query()->whereKey($order->id)->where('confirm_status', 'pending')->whereNotIn('status', ['served', 'cancelled'])
            ->update(['confirm_status' => self::ACTIONS[$action], 'confirm_via' => $via, 'confirm_replied_at' => now()]);
        if ($claimed !== 1) {
            return 'repeat';
        }
        $order->refresh();
        Audit::record('order.confirm.'.$action, '#'.$order->number, ['id' => $order->id, 'via' => $via]);

        return 'applied';
    }

    /** Staff called the guest and the order is fine: «اتأكد بالتليفون». */
    public function confirmByPhone(Order $order, User $by): bool
    {
        if (in_array($order->status, ['served', 'cancelled'], true) || in_array($order->confirm_status, [null, 'confirmed'], true)) {
            return false;
        }
        $order->update(['confirm_status' => 'confirmed', 'confirm_via' => 'phone', 'confirm_by' => $by->id, 'confirm_replied_at' => now()]);
        Audit::record('order.confirm.phone', '#'.$order->number, ['id' => $order->id]);

        return true;
    }

    /** What the guest reads after a tap (WhatsApp reply or the confirmation page). */
    public function replyText(Order $order, string $action, string $result): string
    {
        $en = WhatsApp::locale() === 'en';
        $phone = trim((string) ($order->branch?->phone ?: Settings::get('phone')));
        $call = $phone !== '' ? ' '.($en ? "You can also call us: $phone" : "ولو حابب تكلّمنا: $phone") : '';
        $n = '#'.$order->number;

        if ($result === 'closed') {
            return $en ? "Order $n does not need a confirmation any more (it is finished or cancelled). Thank you 💙".$call
                : "طلب $n مبقاش محتاج تأكيد (خلص أو اتلغى). شكراً ليك 💙".$call;
        }
        if ($result === 'repeat') {
            return $en ? "We already have your answer for order $n 👍".$call : "ردّك على طلب $n وصلنا قبل كده 👍".$call;
        }

        return match ($action) {
            'confirm' => $en ? "Great! ✅ Order $n is confirmed; we are preparing it and will send it as soon as possible."
                : "تمام! ✅ أكدنا طلبك $n وهنجهّزه ونبعته في أقرب وقت.",
            'edit' => ($en ? 'Sure, we will call you right away to change the order.' : 'تمام، هنكلمك حالاً نعدّل الطلب.').$call,
            default => ($en ? "We passed your cancellation request for order $n to the restaurant ✋ They will call you right away."
                : "وصّلنا طلب إلغاء الطلب $n للمطعم ✋ هيكلمك حالاً يأكد معاك.").$call,
        };
    }

    /**
     * A tap on a quick-reply button, from the inbound webhook (no tenant context). The payload names the order by
     * its random token; the tap must come from the order's own mobile. The order's restaurant is opened only for
     * this answer. A tap that reached a restaurant's own number ($via) only ever answers that restaurant's orders,
     * and the reply leaves from that same number. Returns the order answered, or null when the tap was ignored.
     */
    public function handleTap(string $from, string $payload, ?WhatsAppConnection $via = null): ?Order
    {
        if (! preg_match(self::PAYLOAD, trim($payload), $m) || tenancy()->initialized) {
            return null;
        }
        [$action, $token] = [$m[1], $m[2]];
        $found = Order::query()->withoutGlobalScopes()->where('confirm_token', $token)
            ->when($via, fn ($q) => $q->where('tenant_id', $via->tenant_id))->first(['id', 'tenant_id', 'customer_phone']);
        $sender = normalize_phone($from);
        if (! $found || $sender === null || $sender !== normalize_phone((string) $found->customer_phone)) {
            Log::info('whatsapp order confirmation ignored', ['known_order' => $found !== null]);

            return null;
        }
        $tenant = Tenant::query()->find($found->tenant_id);
        if (! $tenant) {
            return null;
        }

        tenancy()->initialize($tenant);
        Settings::flush();
        try {
            $order = Order::query()->with('branch')->find($found->id);
            if (! $order) {
                return null;
            }
            $result = $this->answer($order, $action, 'whatsapp');
            // a guest tapping again and again gets one reply a minute, not one per tap
            if (Cache::add('wa:order-confirm-reply:'.$order->id, 1, 60) || $result === 'applied') {
                $this->whatsapp->reply((string) $order->customer_phone, $this->replyText($order, $action, $result), $order->customer_id,
                    $via ? 'own' : 'platform');
            }

            return $order;
        } catch (\Throwable $e) {
            report($e); // Meta always gets its 200: a retried payload must not answer twice

            return null;
        } finally {
            tenancy()->end();
            Settings::flush();
            Entitlements::flush();
        }
    }

    /** The confirmation message never reached the guest: no badge waits for an answer that cannot come. */
    public static function messageFailed(WhatsAppMessage $message): void
    {
        if ($message->template !== 'order_confirm') {
            return;
        }
        Order::query()->withoutGlobalScopes()->where('tenant_id', $message->tenant_id)->where('confirm_message_id', $message->id)
            ->where('confirm_status', 'pending')->update(['confirm_status' => null]);
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
