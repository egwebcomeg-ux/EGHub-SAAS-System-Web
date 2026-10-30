<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place the sales team works that has no EgHub account yet («عميل محتمل»): imported from the map, added by the field
 * rep on a visit, a referral or an ad. The caller phones it and books a visit (Appointment); when the place signs up
 * with the same mobile it is linked to its restaurant (App\Services\Crm\ProspectLinker) and its first paid invoice
 * wins it (Lead::markWon), which credits the closer and the assist for the commission (App\Support\SalesPlan).
 */
class Prospect extends Model
{
    protected $table = 'crm_prospects';

    protected $guarded = ['id'];

    /** category => [arabic, english, emoji] */
    public const CATEGORIES = [
        'restaurant' => ['مطعم', 'Restaurant', '🍽️'],
        'cafe' => ['كافيه', 'Cafe', '☕'],
        'bakery' => ['مخبوزات', 'Bakery', '🥖'],
        'sweets' => ['حلواني', 'Sweets shop', '🍰'],
        'other' => ['تاني', 'Other', '🏪'],
    ];

    /** stage => [arabic, english, badge classes] */
    public const STAGES = [
        'new' => ['جديد', 'New', 'bg-brand-100 text-brand-800'],
        'contacted' => ['اتكلمنا معاه', 'Contacted', 'bg-sky-100 text-sky-800'],
        'appointment' => ['عليه زيارة', 'Visit booked', 'bg-violet-100 text-violet-800'],
        'visited' => ['اتزار', 'Visited', 'bg-amber-100 text-amber-800'],
        'trial' => ['سجّل وبيجرب', 'Signed up (trial)', 'bg-teal-100 text-teal-800'],
        'won' => ['اشترك', 'Subscribed', 'bg-emerald-100 text-emerald-800'],
        'lost' => ['مش مهتم', 'Not interested', 'bg-navy-200 text-navy-700'],
    ];

    /** Stages the team picks by hand (appointment, visited, trial and won come from what happened). */
    public const MANUAL_STAGES = ['new', 'contacted', 'lost'];

    /** Stages still worth work. */
    public const OPEN_STAGES = ['new', 'contacted', 'appointment', 'visited'];

    public const SOURCES = [
        'map' => ['من الخريطة', 'Map import'],
        'field' => ['المندوب في الشارع', 'Field visit'],
        'referral' => ['ترشيح', 'Referral'],
        'ad' => ['إعلان', 'Ad'],
        'whatsapp' => ['واتساب', 'WhatsApp'],
        'manual' => ['اتضاف بإيد', 'Added by hand'],
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'has_delivery' => 'boolean',
            'branches' => 'integer',
            'score' => 'integer',
            'next_follow_up_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'linked_at' => 'datetime',
            'won_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProspectEvent::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'caller_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'field_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'closer_id');
    }

    public function assist(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'assist_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @param Builder<Prospect> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('crm_prospects.stage', self::OPEN_STAGES);
    }

    /**
     * What $admin may see: everything for whoever follows customers up (crm.manage); a field rep only the places
     * assigned to him.
     *
     * @param  Builder<Prospect>  $query
     */
    public function scopeVisibleTo(Builder $query, PlatformAdmin $admin): void
    {
        if (! $admin->allows('crm.manage')) {
            $query->where('crm_prospects.field_id', $admin->id);
        }
    }

    public function isVisibleTo(PlatformAdmin $admin): bool
    {
        return $admin->allows('crm.manage') || ($this->field_id !== null && $this->field_id === $admin->id);
    }

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN_STAGES, true);
    }

    public function hasLocation(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    public function stageLabel(): string
    {
        return Lead::pick(self::STAGES[$this->stage] ?? [$this->stage, $this->stage]);
    }

    public function stageClasses(): string
    {
        return self::STAGES[$this->stage][2] ?? 'bg-navy-100 text-navy-700';
    }

    public function categoryLabel(): string
    {
        return Lead::pick(self::CATEGORIES[$this->category] ?? [$this->category, $this->category]);
    }

    public function categoryEmoji(): string
    {
        return self::CATEGORIES[$this->category][2] ?? '🏪';
    }

    public function sourceLabel(): string
    {
        return Lead::pick(self::SOURCES[$this->source] ?? [$this->source, $this->source]);
    }

    /** "طنطا · شارع البحر" for lists. */
    public function place(): string
    {
        return collect([$this->city, $this->area])->filter()->implode(' · ');
    }

    /** Distance in metres between two points (haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
