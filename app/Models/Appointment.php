<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visit the caller books for the field rep at a prospect, and his report of it: the check-in (where his phone was,
 * how far from the place), what came out of it, the data he collected and the menu photo.
 */
class Appointment extends Model
{
    protected $table = 'crm_appointments';

    protected $guarded = ['id'];

    public const STATUSES = [
        'scheduled' => ['في الميعاد', 'Scheduled', 'bg-sky-100 text-sky-800'],
        'done' => ['اتزار', 'Visited', 'bg-emerald-100 text-emerald-800'],
        'no_show' => ['ملقاش حد', 'Nobody there', 'bg-amber-100 text-amber-800'],
        'cancelled' => ['اتلغى', 'Cancelled', 'bg-navy-200 text-navy-700'],
    ];

    public const OUTCOMES = [
        'interested' => ['مهتم وعايز يفكر', 'Interested'],
        'trial_signed' => ['سجّل وبدأ تجربة', 'Signed up for the trial'],
        'paid' => ['اشترك ودفع', 'Subscribed and paid'],
        'think' => ['هيرد علينا', 'Will get back to us'],
        'not_interested' => ['مش مهتم', 'Not interested'],
        'closed' => ['المحل مقفول', 'Place closed'],
    ];

    /** The questions of the visit form: key => [arabic, english, input type]. */
    public const QUESTIONS = [
        'decision_maker' => ['مين صاحب القرار (الاسم والصفة)', 'Who decides (name and role)', 'text'],
        'tables' => ['عدد الترابيزات', 'Number of tables', 'number'],
        'branches' => ['عدد الفروع', 'Number of branches', 'number'],
        'delivery' => ['عنده دليفري؟', 'Does delivery?', 'yesno'],
        'current_system' => ['شغال بسيستم إيه دلوقتي', 'Current system', 'text'],
        'menu_kind' => ['المنيو حالياً (ورق، صورة، QR)', 'Menu today (paper, photo, QR)', 'text'],
        'pain' => ['أكتر حاجة تاعباه', 'Biggest pain', 'text'],
    ];

    /** Furthest a check-in may be from the place and still count as "at the place". */
    public const NEAR_METRES = 300;

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'reported_at' => 'datetime',
            'check_in_lat' => 'float',
            'check_in_lng' => 'float',
            'check_in_distance' => 'integer',
            'answers' => 'array',
            'photos' => 'array',
        ];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'set_by');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'field_id');
    }

    /** @param Builder<Appointment> $query */
    public function scopeVisibleTo(Builder $query, PlatformAdmin $admin): void
    {
        if (! $admin->allows('crm.manage')) {
            $query->where('crm_appointments.field_id', $admin->id);
        }
    }

    public function statusLabel(): string
    {
        return Lead::pick(self::STATUSES[$this->status] ?? [$this->status, $this->status]);
    }

    public function statusClasses(): string
    {
        return self::STATUSES[$this->status][2] ?? 'bg-navy-100 text-navy-700';
    }

    public function outcomeLabel(): string
    {
        return $this->outcome ? Lead::pick(self::OUTCOMES[$this->outcome] ?? [$this->outcome, $this->outcome]) : '';
    }

    /** Was the check-in at the place (or the place has no pin to compare with)? */
    public function checkedInNear(): ?bool
    {
        return $this->check_in_distance === null ? null : $this->check_in_distance <= self::NEAR_METRES;
    }
}
