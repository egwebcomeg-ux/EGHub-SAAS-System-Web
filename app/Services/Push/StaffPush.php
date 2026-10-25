<?php

namespace App\Services\Push;

use App\Jobs\SendPushNotification;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Orders\OrderConfirmation;
use Illuminate\Support\Collection;

/**
 * Push notifications to the staff's phones in the EgHub apps (restaurant app: owner, manager, cashier; waiter app:
 * waiters). Each event has the right it needs and the roles that get it unless they switch it off; every staff
 * member picks their own on /notifications/push (users.push_preferences). A user tied to a branch only hears about
 * that branch. Sends are queued (App\Jobs\SendPushNotification) after the database commit; without FCM configured
 * nothing is queued (logged once).
 */
final class StaffPush
{
    /** event => [right needed, roles on by default, Arabic label, English label] */
    public const EVENTS = [
        'order_new' => ['orders.view', ['owner', 'manager', 'cashier'], 'طلب أونلاين أو كول سنتر جديد', 'New online or call-center order'],
        'order_confirm' => ['orders.view', ['owner', 'manager', 'cashier'], 'رد العميل على تأكيد الطلب على واتساب', 'The guest answered the WhatsApp confirmation'],
        'reservation_new' => ['reservations.manage', ['owner', 'manager'], 'حجز ترابيزة جديد', 'New table reservation'],
        'order_ready' => ['orders.view', ['waiter'], 'الطلب جاهز (طلبات الصالة)', 'Order ready (dine-in orders)'],
    ];

    /** Sources whose new orders ring the phones: the guest's own online / WhatsApp orders and the call center's. */
    public const NEW_ORDER_SOURCES = ['online', 'whatsapp', 'callcenter'];

    public function __construct(private FcmClient $fcm) {}

    public static function label(string $event): string
    {
        $e = self::EVENTS[$event] ?? null;

        return $e ? (app()->getLocale() === 'en' ? $e[3] : $e[2]) : $event;
    }

    /** The events $user may receive (has the right), with whether each is on. @return array<string,bool> */
    public static function preferences(User $user): array
    {
        $out = [];
        foreach (self::EVENTS as $event => [$right]) {
            if ($user->can($right)) {
                $out[$event] = self::wants($user, $event);
            }
        }

        return $out;
    }

    public static function wants(User $user, string $event): bool
    {
        [$right, $roles] = self::EVENTS[$event];
        if (! $user->is_active || ! $user->can($right)) {
            return false;
        }
        $saved = (array) ($user->push_preferences ?? []);

        return array_key_exists($event, $saved) ? (bool) $saved[$event] : $user->hasAnyRole($roles);
    }

    public function orderPlaced(Order $order): void
    {
        if (! in_array($order->source, self::NEW_ORDER_SOURCES, true)) {
            return;
        }
        $order->loadMissing('branch');
        $type = Order::TYPES[$order->type][app()->getLocale() === 'en' ? 1 : 0] ?? $order->type;
        $title = $order->source === 'callcenter'
            ? __('New call-center order #:n', ['n' => $order->number])
            : __('New online order #:n', ['n' => $order->number]);
        $this->notify('order_new', $order->branch_id, $title,
            trim($type.' · '.money($order->total).' · '.$order->customer_name, ' ·'),
            ['url' => platform_url('app', '/orders'), 'order' => (string) $order->id]);
    }

    /** The guest answered the confirmation of a cash-on-delivery order (confirmed, wants a change, asks to cancel). */
    public function confirmationAnswered(Order $order): void
    {
        $state = OrderConfirmation::state($order);
        if ($state === null) {
            return;
        }
        $this->notify('order_confirm', $order->branch_id, __('Order #:n', ['n' => $order->number]).': '.OrderConfirmation::label($state),
            trim($order->customer_name.' '.$order->customer_phone),
            ['url' => platform_url('app', '/orders'), 'order' => (string) $order->id]);
    }

    public function reservationCreated(Reservation $reservation): void
    {
        $this->notify('reservation_new', $reservation->branch_id, __('New reservation'),
            __(':name · :n guests · :at', ['name' => $reservation->name, 'n' => $reservation->party_size, 'at' => local_time($reservation->starts_at, 'd/m h:i A')]),
            ['url' => platform_url('app', '/reservations'), 'reservation' => (string) $reservation->id]);
    }

    /** A dine-in order is ready: the waiters take it to the table. */
    public function orderReady(Order $order): void
    {
        if ($order->type !== 'dine_in') {
            return;
        }
        $order->loadMissing('table');
        $this->notify('order_ready', $order->branch_id, __('Order #:n is ready', ['n' => $order->number]),
            $order->table ? __('Table :name', ['name' => $order->table->name]) : '',
            ['url' => platform_url('app', '/orders'), 'order' => (string) $order->id]);
    }

    /**
     * Users of the current restaurant who get $event for $branchId and have a phone registered.
     *
     * @return Collection<int,User>
     */
    public static function recipients(string $event, ?int $branchId): Collection
    {
        return User::query()->where('is_active', true)->whereHas('deviceTokens')
            ->when($branchId, fn ($q) => $q->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->get()->filter(fn (User $u) => self::wants($u, $event))->values();
    }

    /** @param array<string,string> $data */
    private function notify(string $event, ?int $branchId, string $title, string $body, array $data): void
    {
        try {
            if (! $this->fcm->configured()) {
                $this->fcm->noteUnconfigured();

                return;
            }
            $users = self::recipients($event, $branchId);
            if ($users->isEmpty()) {
                return;
            }
            SendPushNotification::dispatch($users->pluck('id')->all(), $title, $body, $data + ['type' => $event])->afterCommit();
        } catch (\Throwable $e) {
            report($e); // a push never breaks taking an order
        }
    }
}
