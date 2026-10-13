<?php

declare(strict_types=1);

/*
 * Small global helpers (host/URL building, money, phones). Loaded through composer "autoload.files".
 */

/** Main domain, e.g. "egmenu.app" ("localhost" while developing). */
function central_domain(): string
{
    return (string) (config('tenancy.central_domains')[0] ?? 'localhost');
}

function scheme(): string
{
    return app()->isProduction() || request()?->isSecure() ? 'https' : 'http';
}

/** ":8000" while developing on a non-standard port, "" otherwise. */
function port_suffix(): string
{
    $port = request()?->getPort();
    return $port && !in_array((int) $port, [80, 443], true) ? ':' . $port : '';
}

/** Absolute URL on a platform host: platform_url('app', '/login'), platform_url(null, 'my-cafe') for a menu. */
function platform_url(?string $sub, string $path = '/'): string
{
    $host = ($sub ? $sub . '.' : '') . central_domain();
    return scheme() . '://' . $host . port_suffix() . '/' . ltrim($path, '/');
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
        $d = '0' . $d;
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
    return '20' . (str_starts_with($d, '0') ? substr($d, 1) : $d);
}

function is_hex_color(?string $c): bool
{
    return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c);
}

/** Date in Cairo time; Arabic AM/PM when the locale is Arabic. */
function local_time(\DateTimeInterface|string|null $at, string $format = 'Y/m/d h:i A'): string
{
    if (!$at) {
        return '—';
    }
    $d = $at instanceof \DateTimeInterface ? \Illuminate\Support\Carbon::instance($at) : \Illuminate\Support\Carbon::parse($at);
    $s = $d->timezone(config('app.timezone'))->format($format);
    return app()->getLocale() === 'ar' ? str_replace(['AM', 'PM'], ['ص', 'م'], $s) : $s;
}

/** Pick the Arabic or English text of a pair / model attribute for the current locale. */
function tr(?string $ar, ?string $en): string
{
    return app()->getLocale() === 'en' && (string) $en !== '' ? (string) $en : (string) $ar;
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
