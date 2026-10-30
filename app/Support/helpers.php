<?php

declare(strict_types=1);
use App\Services\Menu\MenuTranslations;
use App\Support\MenuLocales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/*
 * Small global helpers (host/URL building, money, phones). Loaded through composer "autoload.files".
 */

/** Main domain, e.g. "eghub.app" ("localhost" while developing). */
function central_domain(): string
{
    return (string) (config('tenancy.central_domains')[0] ?? 'localhost');
}

/**
 * Earlier main domains, every CENTRAL_DOMAINS entry after the first (egmenu.app after the move to eghub.app). Their
 * pages answer with a permanent redirect to the same address on the main domain (printed QR codes keep working),
 * except the restaurant directory, which lives on the first of them (routes/web.php).
 *
 * @return list<string>
 */
function legacy_domains(): array
{
    return array_values(array_slice(config('tenancy.central_domains'), 1));
}

/** Host of the public restaurant directory: the first earlier domain when there is one, else the main domain. */
function directory_domain(): string
{
    return legacy_domains()[0] ?? central_domain();
}

function scheme(): string
{
    return app()->isProduction() || request()?->isSecure() ? 'https' : 'http';
}

/** ":8000" while developing on a non-standard port, "" otherwise. */
function port_suffix(): string
{
    $port = request()?->getPort();

    return $port && ! in_array((int) $port, [80, 443], true) ? ':'.$port : '';
}

/** Absolute URL on a platform host: platform_url('app', '/login'), platform_url(null, 'my-cafe') for a menu. */
function platform_url(?string $sub, string $path = '/'): string
{
    $host = ($sub ? $sub.'.' : '').central_domain();

    return scheme().'://'.$host.port_suffix().'/'.ltrim($path, '/');
}

/** Random URL-safe hex token of 2*$bytes characters. */
function random_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function client_ip(): string
{
    return substr((string) (request()?->ip() ?? ''), 0, 45);
}

/** Money display: 52 => "52", 52.5 => "52.5", 1250 => "1,250". */
function money(float|int|string|null $n): string
{
    $s = number_format((float) $n, 2, '.', ',');

    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** Arabic-Indic digits (٠-٩, ٫ ٬) as Latin ones, the way every number in the app is written ("٥٠٠ رسالة" -> "500 رسالة"). */
function latin_digits(string $text): string
{
    return strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ',']);
}

/** Egyptian mobile -> "01xxxxxxxxx" (accepts Arabic digits, +20 / 0020 / 20 prefixes) or null. */
function normalize_phone(string $raw): ?string
{
    $d = preg_replace('/\D+/', '', strtr($raw, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])) ?? '';
    if (str_starts_with($d, '0020')) {
        $d = substr($d, 4);
    } elseif (str_starts_with($d, '20') && strlen($d) === 12) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 10 && $d[0] === '1') {
        $d = '0'.$d;
    }

    return preg_match('/^01[0125]\d{8}$/', $d) ? $d : null;
}

/** Egyptian local number (01220000637) to wa.me international form (201220000637). */
function whatsapp_number(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($d, '20')) {
        return $d;
    }

    return '20'.(str_starts_with($d, '0') ? substr($d, 1) : $d);
}

function is_hex_color(?string $c): bool
{
    return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c);
}

/*
 * Restaurant time. Every time is stored and compared in UTC (app.timezone); the restaurants live in
 * app.local_timezone (Cairo, with daylight saving). Wall-clock questions (what day is it, which hour, which weekday,
 * what to show) go through these helpers. What they return as Carbon is either a local wall clock (local_at) or a
 * UTC instant (local_day_start/end, local_input), so a value meant for a query or a model is never a local one.
 */

/** Timezone of the restaurants: business days, opening hours, schedules and every time shown or typed. */
function local_timezone(): string
{
    return (string) config('app.local_timezone');
}

/**
 * A moment on the restaurant's wall clock (now when null) for its hour, weekday, date or text. Not for queries
 * or model attributes: those take UTC (->utc()).
 */
function local_at(DateTimeInterface|string|null $at = null): Carbon
{
    $d = match (true) {
        $at === null => now(),
        $at instanceof DateTimeInterface => Carbon::instance($at),
        default => Carbon::parse($at),
    };

    return $d->setTimezone(local_timezone());
}

/** The restaurant's business date now (or of the moment given), "Y-m-d": the value for date columns and day keys. */
function local_today(DateTimeInterface|string|null $at = null): string
{
    return local_at($at)->toDateString();
}

/**
 * 00:00 restaurant time of a business day, as the UTC instant to compare timestamps with: today when null, a
 * "Y-m-d" date, or the day a moment falls in.
 */
function local_day_start(DateTimeInterface|string|null $day = null): Carbon
{
    $date = is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : local_today($day);

    return Carbon::parse($date, local_timezone())->startOfDay()->utc();
}

/**
 * The last moment of that business day (just before the next one starts), as a UTC instant. Not 23:59:59 on the
 * wall clock: when summer time ends that hour happens twice and the day ends after the second one.
 */
function local_day_end(DateTimeInterface|string|null $day = null): Carbon
{
    $date = is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : local_today($day);

    return local_day_start(Carbon::parse($date)->addDay()->toDateString())->subMicrosecond();
}

/**
 * A date / date-time the restaurant typed or kept in its settings ("2026-10-29 21:30", "2026-10-29T21:30"), read on
 * its wall clock, as the UTC instant to store or compare. With $format, parsed with that format.
 */
function local_input(string $value, ?string $format = null): Carbon
{
    $d = $format !== null ? Carbon::createFromFormat($format, $value, local_timezone()) : Carbon::parse($value, local_timezone());

    return $d->utc();
}

/** Date in restaurant time; Arabic AM/PM when the locale is Arabic. */
function local_time(DateTimeInterface|string|null $at, string $format = 'Y/m/d h:i A'): string
{
    if (! $at) {
        return '—';
    }
    $s = local_at($at)->format($format);

    return app()->getLocale() === 'ar' ? str_replace(['AM', 'PM'], ['ص', 'م'], $s) : $s;
}

/** Pick the Arabic or English text of a pair / model attribute for the current locale. */
function tr(?string $ar, ?string $en): string
{
    return app()->getLocale() === 'en' && (string) $en !== '' ? (string) $en : (string) $ar;
}

/**
 * Text of a menu row (category, item, option group, option) for the public menu: the extra language the guest
 * picked (App\Support\MenuLocales) when translated, else English, else Arabic — tx($item, 'name').
 */
function tx(?Model $model, string $field): string
{
    if (! $model) {
        return '';
    }
    if ($locale = MenuLocales::current()) {
        $text = MenuTranslations::text($model, $field, $locale);
        if ($text !== null && trim($text) !== '') {
            return $text;
        }
    }

    return tr((string) $model->getAttribute($field), (string) $model->getAttribute($field.'_en'));
}

/** "شهر" / "6 شهور" / "سنة" — billing cycle length in words. */
function cycle_label(int $months): string
{
    return match ($months) {
        1 => __('1 month'),
        12 => __('1 year'),
        default => __(':n months', ['n' => $months]),
    };
}

/**
 * One CSV row with text cells made safe for Excel / Sheets: a cell starting with = + - @ (or a tab / CR) is read as
 * a formula there, so it gets a leading apostrophe. Guests type their own names, so exports must never run them.
 *
 * @param  array<int, mixed>  $row
 * @return array<int, mixed>
 */
function csv_row(array $row): array
{
    return array_map(fn ($v) => is_string($v) && $v !== '' && ! is_numeric($v) && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v, $row);
}

/**
 * URL of a file in public/ with a version stamp (?v=<last change>), so browsers and the Cloudflare cache fetch the new
 * file as soon as it changes — screenshots, icons and the share image keep their names across updates.
 */
function versioned_asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = public_path($path);

    return asset($path).(is_file($file) ? '?v='.base_convert((string) filemtime($file), 10, 36) : '');
}
