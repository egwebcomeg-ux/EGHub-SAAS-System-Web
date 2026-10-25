<?php

namespace App\Services\Screens;

use App\Models\TvAlert;
use App\Models\TvScreen;
use App\Models\TvSlide;
use App\Services\Settings;

/**
 * The fast check of a screen (/screen/live, /tv/{token}/live), asked every POLL_SECONDS: the urgent message it must
 * show now (TvAlert), whether the owner pressed «تحديث الشاشة», and, from a paired device, which slide each area shows
 * («بتعرض دلوقتي» in the dashboard). Cheap on purpose (two small queries, a write only when the slide changed), so it
 * can run every few seconds where the playlist fingerprint (/version) runs every ScreenPlaylist::POLL_SECONDS.
 */
final class ScreenAlerts
{
    /** How often the player asks: an urgent message reaches the screen within this many seconds (plus the request). */
    public const POLL_SECONDS = 8;

    public function __construct(public readonly TvScreen $screen) {}

    /** @return array<string, mixed>|null the newest active message for this screen */
    public function current(): ?array
    {
        $alert = TvAlert::query()->active()->latest('id')->get()->first(fn (TvAlert $a) => $a->showsOn($this->screen->id));
        if (! $alert) {
            return null;
        }
        $color = Settings::get('color_primary');

        return [
            'id' => $alert->id,
            'text' => $alert->text,
            'style' => in_array($alert->style, TvAlert::STYLES, true) ? $alert->style : 'banner',
            'until' => $alert->ends_at ? $alert->ends_at->getTimestampMs() : null,
            'color' => is_hex_color($color) ? $color : '#F26522',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'alert' => $this->current(),
            'reload' => $this->screen->reload_requested_at?->timestamp,
            'poll' => self::POLL_SECONDS,
            'now' => (int) floor(microtime(true) * 1000),
        ];
    }

    /**
     * What the device says it shows: "main:12,side:40" (slide ids, 0 = its logo between slides). Only slides of this
     * screen's own playlist count; written without touching updated_at and only when it changed.
     */
    public function report(string $now): void
    {
        $shown = [];
        foreach (explode(',', mb_substr($now, 0, 60)) as $part) {
            [$zone, $id] = array_pad(explode(':', $part, 2), 2, '');
            if (in_array($zone, TvSlide::ZONES, true) && ctype_digit($id)) {
                $shown[$zone] = (int) $id;
            }
        }
        $previous = (array) ($this->screen->now_showing ?? []);
        unset($previous['at']);
        if ($shown === [] || $shown === $previous) {
            return;
        }
        $ids = $this->screen->playlistOwner()->slides()->pluck('id')->all();
        $shown = array_filter($shown, fn (int $id) => $id === 0 || in_array($id, $ids, true));
        if ($shown === [] || $shown === $previous) {
            return;
        }
        $this->screen->writeQuietly(['now_showing' => $shown + ['at' => now()->timestamp]]);
    }
}
