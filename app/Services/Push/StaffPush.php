<?php

namespace App\Services\Push;

use App\Jobs\SendPushNotification;
use App\Models\CorporateBooking;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Orders\OrderConfirmation;
use App\Services\Reports\DailyDigest;
use Illuminate\Support\Collection;

/**
 * Push notifications to the staff's phones in the EgHub apps (restaurant app: owner, manager, cashier; waiter app:
 * waiters). Each event has the right it needs and the roles that get it unless they switch it off; every staff
 * member picks their own on /notifications/push (users.push_preferences). A user tied to a branch only hears about
 * that branch. Sends are queued (App\Jobs\SendPushNotification) after the database commit; without FCM configured
 * nothing is queued (logged once). The driver app (delivery drivers) hears only about its own orders: one given to
 * them, taken back from them or cancelled (driverAssigned() / driverRemoved()), as data-only high-priority messages
 * the app draws itself on its loud channel and hands to the open page (eg-native-push) to refresh at once.
 */
final class StaffPush
{
    /** event => [right needed, roles on by default, Arabic label, English label] */
    public const EVENTS = [
        'order_new' => ['orders.view', ['owner', 'manager', 'cashier'], 'طلب أونلاين أو كول سنتر جديد', 'New online or call-center order'],
        'hall_order_new' => ['orders.view', ['owner', 'manager', 'waiter'], 'طلب جديد من ترابيزة أو الكشك', 'New table or kiosk order'],
        'order_confirm' => ['orders.view', ['owner', 'manager', 'cashier'], 'رد العميل على تأكيد الطلب على واتساب', 'The guest answered the WhatsApp confirmation'],
        'reservation_new' => ['reservations.manage', ['owner', 'manager'], 'حجز ترابيزة جديد', 'New table reservation'],
        'corporate_booking' => ['contracts.manage', ['owner', 'manager'], 'زيارة شركة مستنية موافقة', 'A company visit waiting for approval'],
        'daily_digest' => ['reports.view', ['owner', 'manager'], 'تقرير المدير اليومي', 'Manager daily report'],
        'order_ready' => ['orders.view', ['waiter'], 'الطلب جاهز (طلبات الصالة)', 'Order ready (dine-in orders)'],
        'driver_order' => ['delivery.drive', ['driver'], 'أوردر جديد معاك، أو اتسحب منك أو اتلغى', 'An order given to you, taken back from you or cancelled'],
    ];

    /** Events only the roles named for them can receive (an owner may do everything, but drives no orders). */
    public const ROLE_ONLY = ['driver_order'];

    /** The message types the driver app gets (data `type`): a new order with them, and one gone from them. */
    public const DRIVER_ASSIGNED = 'driver_order';

    public const DRIVER_REMOVED = 'driver_order_removed';

    /** Sources whose new orders ring the phones: the guest's own online / WhatsApp orders and the call center's. */
    public const NEW_ORDER_SOURCES = ['online', 'whatsapp', 'callcenter'];

    /** Orders guests send from a table QR or the kiosk: the hall's own event, so waiters can have it and not the rest. */
    public const HALL_SOURCES = ['qr', 'kiosk'];

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
        foreach (self::EVENTS as $event => [$right, $roles]) {
            if ($user->can($right) && (! in_array($event, self::ROLE_ONLY, true) || $user->hasAnyRole($roles))) {
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
        if (in_array($event, self::ROLE_ONLY, true) && ! $user->hasAnyRole($roles)) {
            return false;
        }
        $saved = (array) ($user->push_preferences ?? []);

        return array_key_exists($event, $saved) ? (bool) $saved[$event] : $user->hasAnyRole($roles);
    }

    public function orderPlaced(Order $order): void
    {
        if (in_array($order->source, self::HALL_SOURCES, true)) {
            $order->loadMissing('table');
            $this->notify('hall_order_new', $order->branch_id, __('New order #:n', ['n' => $order->number]),
                trim(($order->table ? __('Table :name', ['name' => $order->table->name]).' · ' : '').money($order->total), ' ·'),
                ['url' => platform_url('app', '/orders'), 'order' => (string) $order->id]);

            return;
        }
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

    public function corporateBookingRequested(CorporateBooking $booking): void
    {
        $this->notify('corporate_booking', $booking->branch_id, __('Company visit: :name', ['name' => $booking->contract->name]),
            __(':name · :n guests · :at', ['name' => $booking->rep_name, 'n' => $booking->party_size, 'at' => local_time($booking->visit_at, 'd/m h:i A')]),
            ['url' => platform_url('app', '/corporate/bookings'), 'booking' => (string) $booking->id]);
    }

    /** The morning digest of yesterday (App\Services\Reports\DailyDigest), in one line. @param array<string,mixed> $digest */
    public function dailyDigest(array $digest): void
    {
        $flags = array_filter([
            $digest['cancelled'] ? __(':n cancelled', ['n' => count($digest['cancelled'])]) : null,
            $digest['refunds'] ? __(':n refunds', ['n' => count($digest['refunds'])]) : null,
            DailyDigest::cashOff($digest) ? __('cash off in :n shifts', ['n' => count(DailyDigest::cashOff($digest))]) : null,
            $digest['visits_waiting'] ? __(':n company visits waiting', ['n' => $digest['visits_waiting']]) : null,
        ]);
        $this->notify('daily_digest', null, __('Yesterday: :n orders · :amount', ['n' => $digest['orders'], 'amount' => money($digest['revenue'])]),
            $flags ? implode(' · ', $flags) : __('Nothing needs your attention.'), ['url' => platform_url('app', '/reports')]);
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

    /** A delivery order was given to $driver (or given to them again after a failed try): their phone rings. */
    public function driverAssigned(Order $order, User $driver): void
    {
        $address = (array) ($order->delivery_address ?? []);
        $where = trim(($address['zone'] ?? '').' - '.($address['text'] ?? ''), ' -');
        $collect = $order->amountToCollect();
        $body = collect([
            $order->customer_name,
            $where,
            $collect > 0 ? __('Collect :amount', ['amount' => money($collect)]) : __('Paid already'),
        ])->filter(fn (?string $part) => trim((string) $part) !== '')->implode(' · ');
        $this->notifyDriver($driver, __('New order with you #:n', ['n' => $order->number]), $body,
            ['type' => self::DRIVER_ASSIGNED, 'order' => (string) $order->id, 'priority' => 'high']);
    }

    /** An order $driver was working on is gone from them: given to someone else / taken back, or cancelled. */
    public function driverRemoved(Order $order, User $driver, bool $cancelled): void
    {
        $title = $cancelled
            ? __('Order #:n was cancelled', ['n' => $order->number])
            : __('Order #:n was taken back from you', ['n' => $order->number]);
        $this->notifyDriver($driver, $title, __('Do not deliver it.'),
            ['type' => self::DRIVER_REMOVED, 'order' => (string) $order->id, 'priority' => 'high']);
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

    /**
     * One driver's phones only (never another driver's): a data-only message the driver app draws on its own channel.
     *
     * @param  array<string,string>  $data
     */
    private function notifyDriver(User $driver, string $title, string $body, array $data): void
    {
        try {
            if (! $this->fcm->configured()) {
                $this->fcm->noteUnconfigured();

                return;
            }
            if (! self::wants($driver, 'driver_order') || ! $driver->deviceTokens()->exists()) {
                return;
            }
            SendPushNotification::dispatch([$driver->id], $title, $body, $data + ['url' => platform_url('app', '/driver')], dataOnly: true)->afterCommit();
        } catch (\Throwable $e) {
            report($e); // a push never breaks giving an order to a driver
        }
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
