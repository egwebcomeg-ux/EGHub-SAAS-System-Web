<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A guest / POS / call-center order. Status flow: new → preparing → ready → served, and for delivery
 * ready → on_way → served. Cancelable until served. Status changes go through OrderService so every change is
 * recorded in order_status_histories with its timestamp column.
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
        'on_way' => ['served', 'cancelled'],
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
            'delivery_fee' => 'float',
            'delivery_address' => 'array',
            'scheduled_for' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'loyalty_applied_at' => 'datetime',
            'stock_deducted_at' => 'datetime',
        ];
    }

    public function canMoveTo(string $status): bool
    {
        if ($status === 'on_way' && $this->type !== 'delivery') {
            return false;
        }

        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
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

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }
}
