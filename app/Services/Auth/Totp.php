<?php

namespace App\Services\Auth;

/**
 * Time-based one-time passwords (RFC 6238 over HOTP, RFC 4226) in plain PHP: HMAC-SHA1, 30-second steps, 6 digits.
 * Works with Google Authenticator, Microsoft Authenticator, Authy and any other TOTP app.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh 160-bit secret, base32 encoded (32 characters), as authenticator apps expect it. */
    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The code valid at $timestamp (now by default). */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        return self::hotp(self::base32Decode($secret), intdiv($timestamp ?? time(), self::PERIOD));
    }

    /**
     * True when $code matches the current step or one step either side (clock drift of up to 30 seconds).
     * Compared in constant time; anything that is not exactly 6 digits is rejected without looking at the secret.
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = str_replace(' ', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return false;
        }
        $key = self::base32Decode($secret);
        $step = intdiv($timestamp ?? time(), self::PERIOD);
        $ok = false;
        for ($i = -$window; $i <= $window; $i++) {
            $ok = hash_equals(self::hotp($key, $step + $i), $code) || $ok;
        }

        return $ok;
    }

    /** otpauth:// URI for the QR code the authenticator app scans. */
    public static function uri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private static function hotp(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
