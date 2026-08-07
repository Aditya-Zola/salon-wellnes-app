<?php

namespace App\Http\Support;

use InvalidArgumentException;

final class FixedPoint
{
    public const STOCK_SCALE = 4;

    public const PERCENT_SCALE = 4;

    public static function parse(string|int $value, int $scale): int
    {
        $value = trim((string) $value);

        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Nilai desimal tidak valid.');
        }

        $fraction = $matches[2] ?? '';

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("Nilai hanya boleh memiliki {$scale} angka desimal.");
        }

        $factor = 10 ** $scale;
        $whole = (int) $matches[1];
        $fractionValue = (int) str_pad($fraction, $scale, '0');

        if ($whole > intdiv(PHP_INT_MAX - $fractionValue, $factor)) {
            throw new InvalidArgumentException('Nilai terlalu besar.');
        }

        return ($whole * $factor) + $fractionValue;
    }

    public static function format(int $value, int $scale): string
    {
        if ($value < 0) {
            return '-'.self::format(abs($value), $scale);
        }

        $factor = 10 ** $scale;
        $whole = intdiv($value, $factor);
        $fraction = str_pad((string) ($value % $factor), $scale, '0', STR_PAD_LEFT);

        return $scale === 0 ? (string) $whole : "{$whole}.{$fraction}";
    }

    public static function normalizePercent(string|int $value): string
    {
        return self::format(self::parse($value, self::PERCENT_SCALE), self::PERCENT_SCALE);
    }

    public static function percentOf(int $amount, string|int $percent): int
    {
        $scaledPercent = self::parse($percent, self::PERCENT_SCALE);
        $denominator = 100 * (10 ** self::PERCENT_SCALE);

        if ($scaledPercent > $denominator) {
            throw new InvalidArgumentException('Persentase tidak boleh lebih dari 100.');
        }

        if ($amount !== 0 && $scaledPercent > intdiv(PHP_INT_MAX - intdiv($denominator, 2), $amount)) {
            throw new InvalidArgumentException('Nilai perhitungan terlalu besar.');
        }

        return intdiv(($amount * $scaledPercent) + intdiv($denominator, 2), $denominator);
    }

    public static function multiply(int $left, int $right, int $scale): int
    {
        $factor = 10 ** $scale;

        if ($left !== 0 && $right > intdiv(PHP_INT_MAX - intdiv($factor, 2), $left)) {
            throw new InvalidArgumentException('Nilai perhitungan terlalu besar.');
        }

        return intdiv(($left * $right) + intdiv($factor, 2), $factor);
    }
}
