<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use App\Services\Entitlements;
use Illuminate\Support\Carbon;

/**
 * A seasonal look of the public menu (feature offers): between two local dates the menu shows a greeting strip,
 * the season's banner as cover, an alternate logo, an accent color (through the theme tokens, so every guest page
 * follows) and a light decoration of the preset. When several overlap, the one that started last wins.
 */
class Season extends TenantModel
{
    public const FEATURE = 'offers';

    /**
     * Defaults of each preset: greeting (ar, en), accent, decoration pattern and icon.
     *
     * @var array<string, array{greeting:string, greeting_en:string, accent:?string, pattern:string, icon:string}>
     */
    public const PRESETS = [
        'ramadan' => ['greeting' => 'رمضان كريم', 'greeting_en' => 'Ramadan Kareem', 'accent' => '#0F6B4F', 'pattern' => 'crescent', 'icon' => '🌙'],
        'eid' => ['greeting' => 'كل سنة وانت طيب', 'greeting_en' => 'Eid Mubarak', 'accent' => '#B8860B', 'pattern' => 'lantern', 'icon' => '🏮'],
        'christmas' => ['greeting' => 'كريسماس سعيد', 'greeting_en' => 'Merry Christmas', 'accent' => '#C62828', 'pattern' => 'snow', 'icon' => '🎄'],
        'summer' => ['greeting' => 'صيف حلو ومنعش', 'greeting_en' => 'Hello summer', 'accent' => '#0284C7', 'pattern' => 'sun', 'icon' => '☀️'],
        'valentine' => ['greeting' => 'هابي فالنتاين', 'greeting_en' => "Happy Valentine's", 'accent' => '#E11D48', 'pattern' => 'hearts', 'icon' => '❤️'],
        'custom' => ['greeting' => '', 'greeting_en' => '', 'accent' => null, 'pattern' => 'dots', 'icon' => '✨'],
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'decoration' => 'boolean', 'is_active' => 'boolean'];
    }

    public static function presetLabel(string $preset): string
    {
        return match ($preset) {
            'ramadan' => __('Ramadan'),
            'eid' => __('Eid'),
            'christmas' => __('Christmas'),
            'summer' => __('Summer'),
            'valentine' => __('Valentine'),
            default => __('Custom'),
        };
    }

    /** The season running today in the restaurant's timezone (latest start wins), or null; needs the plan feature. */
    public static function live(): ?self
    {
        if (! tenant() || ! Entitlements::current()->allows(self::FEATURE)) {
            return null;
        }
        $today = self::today();

        return self::query()->where('is_active', true)
            ->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)
            ->orderByDesc('starts_on')->orderByDesc('id')->first();
    }

    /** Today's date where the restaurant is. */
    public static function today(): string
    {
        return Carbon::now(config('app.timezone'))->toDateString();
    }

    public function runsOn(string $date): bool
    {
        return $this->is_active && $this->starts_on->toDateString() <= $date && $this->ends_on->toDateString() >= $date;
    }

    public function greetingText(): string
    {
        return tr($this->greeting, $this->greeting_en);
    }

    public function pattern(): string
    {
        return self::PRESETS[$this->preset]['pattern'] ?? 'dots';
    }

    public function icon(): string
    {
        return self::PRESETS[$this->preset]['icon'] ?? '✨';
    }
}
