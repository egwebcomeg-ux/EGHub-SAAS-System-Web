<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use App\Support\PrepKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A guest / POS / call-center order. Status flow: new → preparing → ready → served, and for delivery
 * ready → on_way → served (on_way → ready when the delivery failed and the order is back at the restaurant).
 * Cancelable until served. Status changes go through OrderService so every change is recorded in
 * order_status_histories with its timestamp column. A delivery order can have a driver (App\Services\Orders\Delivery):
 * delivery_status assigned → picked_up (order on_way) → delivered (order served), or failed with a reason.
 */
class Order extends TenantModel
{
    public const STATUSES = [
        'new' => ['جديد', 'New'],
        'preparing' => ['بيتحضر', 'Preparing'],
        'ready' => ['جاهز', 'Ready'],
        'on_way' => ['في الطريق', 'On the way'],
        'served' => ['اتسلّم', 'Served'],
        'cancelled' => ['ملغي', 'Cancelled'],
    ];

    public const TRANSITIONS = [
        'new' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['served', 'on_way', 'cancelled'],
        'on_way' => ['served', 'ready', 'cancelled'],
        'served' => [],
        'cancelled' => [],
    ];

    public const SOURCES = [
        'qr' => ['منيو QR', 'QR menu'],
        'pos' => ['كاشير', 'POS'],
        'callcenter' => ['كول سنتر', 'Call center'],
        'whatsapp' => ['واتساب', 'WhatsApp'],
        'online' => ['طلب أونلاين', 'Online'],
        'kiosk' => ['كشك', 'Kiosk'],
    ];

    public const TYPES = [
        'dine_in' => ['صالة', 'Dine-in'],
        'takeaway' => ['تيك أواي', 'Takeaway'],
        'delivery' => ['توصيل', 'Delivery'],
    ];

    /** The driver's steps of a delivery order. */
    public const DELIVERY_STATUSES = [
        'assigned' => ['اتحدد الطيار', 'Assigned'],
        'picked_up' => ['خرج للتوصيل', 'Out for delivery'],
        'delivered' => ['اتسلّم للعميل', 'Delivered'],
        'failed' => ['التوصيل ما تمّش', 'Delivery failed'],
    ];

    /** Orders guests place themselves (the board alarm rings for these until someone accepts them). */
    public const GUEST_SOURCES = ['qr', 'online', 'whatsapp'];

    /** cancel_reason written by egmenu:auto-cancel (shown translated to the guest). */
    public const REASON_NOT_ACCEPTED = 'not accepted in time';

    /** The cancel reason as the guest should read it. */
    public function cancelReasonLabel(): ?string
    {
        if (! $this->cancel_reason) {
            return null;
        }

        return $this->cancel_reason === self::REASON_NOT_ACCEPTED
            ? __('Sorry, the restaurant could not accept your order in time, so it was cancelled.') : $this->cancel_reason;
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'discount' => 'float',
            'coupon_discount' => 'float',
            'goal_discount' => 'float',
            'service' => 'float',
            'tax' => 'float',
            'total' => 'float',
            'corporate_amount' => 'float',
            'refunded_amount' => 'float',
            'delivery_fee' => 'float',
            'delivery_address' => 'array',
            'scheduled_for' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'loyalty_applied_at' => 'datetime',
            'stock_deducted_at' => 'datetime',
            'created_offline_at' => 'datetime',
            'synced_at' => 'datetime',
            'driver_assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'delivery_failed_at' => 'datetime',
            'cash_collected' => 'float',
            'cash_settled_at' => 'datetime',
            'needs_review' => 'boolean',
            'review_notes' => 'array',
            'payments' => 'array',
            'pager_calls' => 'integer',
            'pager_called_at' => 'datetime',
            'pager_seen_at' => 'datetime',
            'pager_picked_up_at' => 'datetime',
        ];
    }

    public function canMoveTo(string $status): bool
    {
        if ($status === 'on_way' && $this->type !== 'delivery') {
            return false;
        }

        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function deliveryStatusLabel(): ?string
    {
        if (! $this->delivery_status) {
            return null;
        }
        $l = self::DELIVERY_STATUSES[$this->delivery_status] ?? [$this->delivery_status, $this->delivery_status];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    /** What the driver collects at the door: the total of an order not paid yet, nothing for a paid one. */
    public function amountToCollect(): float
    {
        return $this->payment_status === 'paid' ? 0.0 : round((float) $this->total, 2);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function statusLabel(): string
    {
        $l = self::STATUSES[$this->status] ?? [$this->status, $this->status];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    /** "#121" or, for a sale made offline, "#121 (كان OFF-K1-3)". */
    public function reference(): string
    {
        return '#'.$this->number.($this->offline_ref ? ' ('.__('was :ref', ['ref' => $this->offline_ref]).')' : '');
    }

    /**
     * The receipt's public read-only page on the restaurant's own address (QR on the printed receipt, WhatsApp link):
     * /r/{id}/{signature}. The signature is an HMAC of the restaurant, the order and its private tracking token.
     */
    public function receiptUrl(): string
    {
        return tenant()->url('r/'.$this->id.'/'.$this->receiptSignature());
    }

    public function receiptSignature(): string
    {
        return substr(hash_hmac('sha256', 'receipt|'.$this->tenant_id.'|'.$this->id.'|'.$this->tracking_token, (string) config('app.key')), 0, 32);
    }

    /**
     * The guest pager of this order (App\Services\Orders\Pager) on the restaurant's own address: /p/{id}-{signature}
     * from the QR on the receipt. The signature is an HMAC of the restaurant, the order and its private tracking
     * token, so the link opens this order's pager only and says nothing of the tracking page's address or items.
     */
    public function pagerKey(): string
    {
        return $this->id.'-'.substr(hash_hmac('sha256', 'pager|'.$this->tenant_id.'|'.$this->id.'|'.$this->tracking_token, (string) config('app.key')), 0, 24);
    }

    public function pagerUrl(): string
    {
        return tenant()->url('p/'.$this->pagerKey());
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    /**
     * The food / drinks parts of an order and whether each is ready, e.g. ['food' => true, 'drinks' => false].
     * Empty for an order with one kind only: it keeps a single "ready".
     *
     * @return array<string,bool>
     */
    public function parts(): array
    {
        $parts = [];
        $done = in_array($this->status, ['ready', 'on_way', 'served'], true); // older orders have no line stamps
        foreach ($this->items as $item) {
            $kind = $item->prep_kind ?: PrepKind::FOOD;
            $parts[$kind] = ($parts[$kind] ?? true) && ($done || $item->ready_at !== null);
        }
        $ordered = []; // food first, then drinks, on every screen
        foreach (array_keys(PrepKind::KINDS) as $kind) {
            if (isset($parts[$kind])) {
                $ordered[$kind] = $parts[$kind];
            }
        }

        return count($ordered) > 1 ? $ordered : [];
    }

    /** @return list<string> the kinds of lines in the order (food, drinks) */
    public function kinds(): array
    {
        return array_values(array_unique($this->items->map(fn ($i) => $i->prep_kind ?: PrepKind::FOOD)->all()));
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }
}
