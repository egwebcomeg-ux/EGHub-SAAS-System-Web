<?php

namespace App\Support;

use App\Models\TvScreen;
use Illuminate\Validation\Rule;

/**
 * «مقاس الشاشة واتجاهها»: how a screen hangs (landscape, or portrait turned right / left) and how big it is and how far
 * the guests stand, which set the player's text size. The player sizes everything in rem from the screen's short
 * side; scale() multiplies that, so a small or far screen gets bigger text (fewer items per page) and a big, close
 * one smaller text (more items). The owner can set the percent by hand («حجم الكلام»).
 */
final class ScreenShape
{
    public const SHAPES = ['landscape', 'portrait'];

    public const TURNS = ['right', 'left'];

    /** inches => automatic text size (percent) at a middle distance; small = a tablet or a small monitor */
    public const SIZES = ['small' => 125, '32' => 115, '43' => 105, '50' => 100, '55' => 100, '65' => 95, '75' => 90];

    /** how far the guests stand => factor on the size's percent */
    public const DISTANCES = ['near' => 0.9, 'mid' => 1.0, 'far' => 1.2];

    public const MIN_SCALE = 80;

    public const MAX_SCALE = 150;

    /** @return array<string, mixed> validation rules of the shape fields (all optional) */
    public static function rules(): array
    {
        return [
            'shape' => ['nullable', Rule::in(self::SHAPES)],
            'turn' => ['nullable', Rule::in(self::TURNS)],
            'screen_size' => ['nullable', Rule::in(array_map('strval', array_keys(self::SIZES)))],
            'viewing_distance' => ['nullable', Rule::in(array_keys(self::DISTANCES))],
            'text_scale' => ['nullable', 'integer', 'min:'.self::MIN_SCALE, 'max:'.self::MAX_SCALE],
        ];
    }

    /**
     * The screen columns from validated input: orientation from shape + turn, the size and distance, and the hand-set
     * text size only when «تلقائي» is off ($manual).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributes(array $data, bool $manual): array
    {
        $out = [];
        if (! empty($data['shape'])) {
            $out['orientation'] = $data['shape'] === 'landscape' ? 'landscape' : (($data['turn'] ?? 'right') === 'left' ? 'portrait2' : 'portrait');
        }
        $out['screen_size'] = isset($data['screen_size']) ? (string) $data['screen_size'] : null;
        $out['viewing_distance'] = $data['viewing_distance'] ?? null;
        $out['text_scale'] = $manual && isset($data['text_scale']) ? (int) $data['text_scale'] : null;

        return $out;
    }

    /** The automatic text size in percent for a size and a distance (100 when neither is known). */
    public static function autoPercent(?string $size, ?string $distance): int
    {
        $percent = (self::SIZES[$size ?? ''] ?? 100) * (self::DISTANCES[$distance ?? ''] ?? 1.0);

        return (int) max(self::MIN_SCALE, min(self::MAX_SCALE, round($percent / 5) * 5));
    }

    /** The percent the screen plays at: the hand-set one, else the automatic one. */
    public static function percent(TvScreen $screen): int
    {
        return $screen->text_scale ? (int) max(self::MIN_SCALE, min(self::MAX_SCALE, $screen->text_scale)) : self::autoPercent($screen->screen_size, $screen->viewing_distance);
    }

    /** The factor the player multiplies its root size by. */
    public static function scale(TvScreen $screen): float
    {
        return round(self::percent($screen) / 100, 2);
    }

    public static function shapeOf(TvScreen $screen): string
    {
        return in_array($screen->orientation, ['portrait', 'portrait2'], true) ? 'portrait' : 'landscape';
    }

    public static function turnOf(TvScreen $screen): string
    {
        return $screen->orientation === 'portrait2' ? 'left' : 'right';
    }

    /** @return array<string, string> */
    public static function sizeLabels(): array
    {
        $inches = fn (string $n): string => __(':n inch', ['n' => $n]);

        return ['small' => __('Tablet or small screen'), '32' => $inches('32'), '43' => $inches('43'), '50' => $inches('50'), '55' => $inches('55'), '65' => $inches('65'), '75' => __('75 inch or more')];
    }

    /** @return array<string, string> */
    public static function distanceLabels(): array
    {
        return ['near' => __('Close (at the counter)'), 'mid' => __('Middle (across the room)'), 'far' => __('Far (from the door or the street)')];
    }

    /** «بالطول · 55″ · بعيدة» for the screen card; null parts are left out. */
    public static function summary(TvScreen $screen): string
    {
        $parts = [match ($screen->orientation) {
            'portrait' => __('Portrait, turned right'),
            'portrait2' => __('Portrait, turned left'),
            default => __('Landscape (wide)'),
        }];
        if ($screen->screen_size && isset(self::sizeLabels()[$screen->screen_size])) {
            $parts[] = self::sizeLabels()[$screen->screen_size];
        }
        if ($screen->text_scale) {
            $parts[] = __('Text :percent%', ['percent' => $screen->text_scale]);
        }

        return implode(' · ', $parts);
    }
}
