<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic on numeric strings (bcmath). Used for quantities,
 * tolerances and conversion factors so nothing is silently rounded by
 * floating point.
 */
final class Decimal
{
    public const SCALE = 20;

    public static function isNumeric(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value)
            ? (bool) preg_match('/^-?\d+(\.\d+)?$/', self::raw($value))
            : false;
    }

    /** Canonical form: no exponent, no trailing zeros ("1000.500" → "1000.5"). */
    public static function normalize(mixed $value): string
    {
        $raw = self::raw($value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            throw new InvalidArgumentException("Not a decimal number: {$raw}");
        }

        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '-');
        [$int, $frac] = array_pad(explode('.', $raw, 2), 2, '');
        $int = ltrim($int, '0') ?: '0';
        $frac = rtrim($frac, '0');
        $result = $frac === '' ? $int : "{$int}.{$frac}";

        return $negative && $result !== '0' ? "-{$result}" : $result;
    }

    public static function decimals(mixed $value): int
    {
        $normalized = self::normalize($value);

        return str_contains($normalized, '.') ? strlen(explode('.', $normalized)[1]) : 0;
    }

    public static function mul(string $a, string $b): string
    {
        return self::normalize(bcmul(self::normalize($a), self::normalize($b), self::SCALE));
    }

    public static function div(string $a, string $b): string
    {
        if (self::cmp($b, '0') === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return self::normalize(bcdiv(self::normalize($a), self::normalize($b), self::SCALE));
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function isPositive(mixed $value): bool
    {
        return self::isNumeric($value) && self::cmp(self::raw($value), '0') > 0;
    }

    /** Equal within a relative tolerance (default 1e-9) to absorb stored-precision noise. */
    public static function approximatelyEqual(string $a, string $b, string $relative = '0.000000001'): bool
    {
        $diff = ltrim(bcsub(self::normalize($a), self::normalize($b), self::SCALE), '-');
        $scale = ltrim(self::normalize($b), '-');
        $allowed = bcmul($scale === '0' ? '1' : $scale, $relative, self::SCALE);

        return bccomp($diff, $allowed, self::SCALE) <= 0;
    }

    /** Rounds half-up to $places decimals. */
    public static function round(string $value, int $places): string
    {
        $value = self::normalize($value);
        $offset = '0.'.str_repeat('0', $places).'5';
        $result = str_starts_with($value, '-')
            ? bcsub($value, $offset, $places)
            : bcadd($value, $offset, $places);

        return self::normalize($result);
    }

    private static function raw(mixed $value): string
    {
        if (is_float($value)) {
            // Avoid exponent notation and binary noise for floats.
            return rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.') ?: '0';
        }

        return trim((string) $value);
    }
}
