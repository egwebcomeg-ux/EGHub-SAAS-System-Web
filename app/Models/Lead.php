<?php

namespace App\Models;

use App\Services\Crm\ProspectLinker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A restaurant as seen by EgWeb's sales follow-up («متابعة العملاء», admin.<domain>/crm). One per restaurant, made when
 * it is created (Tenant::created) and backfilled for older ones (App\Services\Crm\LeadBackfill). Platform data only:
 * no restaurant ever reads it. Won is set by the first paid subscription invoice (Billing::markPaid), never by hand.
 */
class Lead extends Model
{
    protected $table = 'crm_leads';

    protected $guarded = ['id'];

    /** stage => [arabic, english, badge classes] */
    public const STAGES = [
        'new' => ['جديد', 'New', 'bg-brand-100 text-brand-800'],
        'contacted' => ['تم التواصل', 'Contacted', 'bg-sky-100 text-sky-800'],
        'interested' => ['مهتم / عرض مقدّم', 'Interested / offer made', 'bg-violet-100 text-violet-800'],
        'callback' => ['اتصل لاحقاً', 'Call back later', 'bg-amber-100 text-amber-800'],
        'won' => ['اشترك', 'Subscribed', 'bg-emerald-100 text-emerald-800'],
        'lost' => ['مش مهتم', 'Not interested', 'bg-navy-200 text-navy-700'],
    ];

    /** Stages a caller picks by hand (won comes from a paid invoice only). */
    public const MANUAL_STAGES = ['new', 'contacted', 'interested', 'callback', 'lost'];

    /** Stages still worth a call. */
    public const OPEN_STAGES = ['new', 'contacted', 'interested', 'callback'];

    public const SOURCES = [
        'signup' => ['سجّل بنفسه', 'Signed up'],
        'manual' => ['فريقنا عمله', 'Created by the team'],
    ];

    /** Why a lead said no. */
    public const LOST_REASONS = [
        'price' => ['السعر', 'Price'],
        'has_system' => ['عنده سيستم تاني', 'Uses another system'],
        'no_need' => ['مش محتاج دلوقتي', 'No need right now'],
        'closed' => ['المحل قفل أو لسه ما فتحش', 'Place closed or not open yet'],
        'testing' => ['كان بيجرب بس', 'Was only trying it'],
        'unreachable' => ['مش عارفين نوصله', 'Could not reach them'],
        'other' => ['سبب تاني', 'Other'],
    ];

    /** Call outcomes (a WhatsApp message is "sent"). */
    public const OUTCOMES = [
        'answered' => ['رد', 'Answered'],
        'no_answer' => ['مردّش', 'No answer'],
        'busy' => ['مشغول', 'Busy'],
        'wrong_number' => ['رقم غلط', 'Wrong number'],
        'sent' => ['اتبعتت رسالة واتساب', 'WhatsApp sent'],
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'next_follow_up_at' => 'datetime',
            'first_contact_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'won_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'assigned_admin_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeadEvent::class);
    }

    /** The lead of $tenant, made when missing (source signup unless told otherwise). */
    public static function for(Tenant $tenant, string $source = 'signup'): self
    {
        return static::query()->firstOrCreate(['tenant_id' => $tenant->id], ['source' => $source, 'stage' => 'new']);
    }

    /**
     * The restaurant paid a subscription invoice: the lead is won (once; later payments change nothing).
     */
    public static function markWon(Tenant $tenant, Invoice $invoice): void
    {
        $lead = static::for($tenant);
        if ($lead->stage === 'won') {
            return;
        }
        $from = $lead->stage;
        $lead->update(['stage' => 'won', 'won_at' => now(), 'next_follow_up_at' => null]);
        $lead->events()->create(['type' => 'won', 'meta' => ['from' => $from, 'invoice' => $invoice->number, 'total' => $invoice->total]]);
        // and the field sales prospect it came from, with who closed it (the commission)
        app(ProspectLinker::class)->won($tenant, $invoice);
    }

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN_STAGES, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->next_follow_up_at !== null && $this->next_follow_up_at->lt(self::todayStart());
    }

    public function isDueToday(): bool
    {
        return $this->isOpen() && $this->next_follow_up_at !== null
            && $this->next_follow_up_at->betweenIncluded(self::todayStart(), self::tomorrowStart()->subSecond());
    }

    public function stageLabel(): string
    {
        return self::pick(self::STAGES[$this->stage] ?? [$this->stage, $this->stage]);
    }

    public function stageClasses(): string
    {
        return self::STAGES[$this->stage][2] ?? 'bg-navy-100 text-navy-700';
    }

    public function sourceLabel(): string
    {
        return self::pick(self::SOURCES[$this->source] ?? [$this->source, $this->source]);
    }

    public function lostReasonLabel(): string
    {
        return $this->lost_reason ? self::pick(self::LOST_REASONS[$this->lost_reason] ?? [$this->lost_reason, $this->lost_reason]) : '';
    }

    /** @param Builder<Lead> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('crm_leads.stage', self::OPEN_STAGES);
    }

    /** Open leads whose follow-up is before today (Cairo time). @param Builder<Lead> $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->open()->where('crm_leads.next_follow_up_at', '<', self::todayStart());
    }

    /** Open leads with a follow-up today. @param Builder<Lead> $query */
    public function scopeDueToday(Builder $query): void
    {
        $query->open()->where('crm_leads.next_follow_up_at', '>=', self::todayStart())
            ->where('crm_leads.next_follow_up_at', '<', self::tomorrowStart());
    }

    /** New leads nobody called or messaged yet. @param Builder<Lead> $query */
    public function scopeUncontacted(Builder $query): void
    {
        $query->where('crm_leads.stage', 'new')->whereNull('crm_leads.first_contact_at');
    }

    /** Midnight today in restaurant time (Cairo), as the UTC instant follow-ups are compared with. */
    public static function todayStart(): Carbon
    {
        return local_day_start();
    }

    /** Midnight tomorrow in restaurant time, as a UTC instant (a daylight-saving day is 23 or 25 hours long). */
    public static function tomorrowStart(): Carbon
    {
        return local_day_start(local_at()->addDay()->toDateString());
    }

    /** @param array{0: string, 1: string} $pair */
    public static function pick(array $pair): string
    {
        return app()->getLocale() === 'en' ? $pair[1] : $pair[0];
    }
}
