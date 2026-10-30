<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a prospect's timeline: a call, a WhatsApp, a booked visit, a visit report, a stage change, the sign-up that linked it, the payment that won it. */
class ProspectEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'crm_prospect_events';

    protected $guarded = ['id'];

    public const TYPES = [
        'call' => ['مكالمة', 'Call'],
        'whatsapp' => ['واتساب', 'WhatsApp'],
        'appointment' => ['ميعاد زيارة', 'Visit booked'],
        'visit' => ['زيارة', 'Visit'],
        'stage' => ['تغيير المرحلة', 'Stage changed'],
        'assign' => ['تعيين', 'Assigned'],
        'note' => ['ملاحظة', 'Note'],
        'import' => ['اتضاف للسيستم', 'Added'],
        'linked' => ['سجّل على EgHub', 'Signed up on EgHub'],
        'won' => ['دفع واشترك', 'Paid and subscribed'],
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
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
        return $this->outcome ? Lead::pick(Lead::OUTCOMES[$this->outcome] ?? Appointment::OUTCOMES[$this->outcome] ?? [$this->outcome, $this->outcome]) : '';
    }
}
