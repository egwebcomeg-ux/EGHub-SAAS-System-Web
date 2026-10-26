<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a lead's timeline: a call or a WhatsApp attempt (outcome, answers, objections, notes, next follow-up),
 * a stage change, an assignment, a discount, a note, or the payment that won it.
 */
class LeadEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'crm_lead_events';

    protected $guarded = ['id'];

    public const TYPES = [
        'call' => ['مكالمة', 'Call'],
        'whatsapp' => ['واتساب', 'WhatsApp'],
        'stage' => ['تغيير المرحلة', 'Stage changed'],
        'assign' => ['تعيين مسؤول', 'Assigned'],
        'discount' => ['خصم', 'Discount'],
        'note' => ['ملاحظة', 'Note'],
        'won' => ['دفع واشترك', 'Paid and subscribed'],
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'objections' => 'array',
            'meta' => 'array',
            'next_follow_up_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }

    public function typeLabel(): string
    {
        return Lead::pick(self::TYPES[$this->type] ?? [$this->type, $this->type]);
    }

    public function outcomeLabel(): string
    {
        return $this->outcome ? Lead::pick(Lead::OUTCOMES[$this->outcome] ?? [$this->outcome, $this->outcome]) : '';
    }
}
