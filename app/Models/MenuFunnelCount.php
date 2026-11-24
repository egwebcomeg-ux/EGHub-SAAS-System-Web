<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Support\Carbon;

/**
 * Daily counters of the guest's way through the public menu (no personal data): each step a visit reached, and what
 * stopped a guest from ordering. The page sends each step once per visit; refusals are counted by the server.
 */
class MenuFunnelCount extends TenantModel
{
    public $timestamps = false;

    /** The steps in order: opened the menu → an item → added → the cart → pressed send → order placed. */
    public const STEPS = ['visit', 'item', 'add', 'cart', 'send', 'placed'];

    /** What the page itself stops on before sending (the cart's own checks). */
    public const STOPS = ['way', 'phone', 'zone', 'pin', 'street'];

    /** A stop of the cart's own checks, or the restaurant's refusal message when the server says no. */
    public const STOP = 'stop';

    public const REFUSED = 'refused';

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    /** Adds one to today's counter of the event (and its detail). */
    public static function bump(string $event, string $detail = ''): void
    {
        $day = Carbon::parse(local_today())->toDateString();
        $detail = mb_substr(trim($detail), 0, 150);
        // the day's row is made at most once (the unique key ignores the second guest's insert), then both count
        static::query()->toBase()->insertOrIgnore(['tenant_id' => tenant()->getTenantKey(), 'day' => $day, 'event' => $event, 'detail' => $detail]);
        static::query()->whereDate('day', $day)->where('event', $event)->where('detail', $detail)->increment('count');
    }
}
