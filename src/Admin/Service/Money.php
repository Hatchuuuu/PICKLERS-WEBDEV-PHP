<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use InvalidArgumentException;

/**
 * Exact peso amounts as integer centavos.
 *
 * Amounts enter as strings (form input or DECIMAL(10,2) columns, which PDO returns
 * as strings) and are converted without passing through binary floating point, so
 * ₱0.10 + ₱0.20 is exactly ₱0.30 and a balance can be compared to the centavo.
 */
final class Money
{
    /** DECIMAL(10,2) ceiling: 99,999,999.99 */
    public const MAX_CENTAVOS = 9_999_999_999;

    /** Parse "1234.5", "1,234.50" or "₱1,234.50" into centavos; rejects anything else. */
    public static function parse(string|int|float|null $value): int
    {
        if ($value === null) {
            throw new InvalidArgumentException('Amount is required.');
        }
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            // Only reached for legacy float values; round to the nearest centavo.
            $value = number_format($value, 2, '.', '');
        }
        $clean = str_replace([',', '₱', ' '], '', trim($value));
        if (!preg_match('/^(-)?(\d{1,10})(?:\.(\d{1,2}))?$/', $clean, $m)) {
            throw new InvalidArgumentException('Enter an amount in pesos with at most two decimal places.');
        }
        $centavos = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '0', 2, '0');
        if ($centavos > self::MAX_CENTAVOS) {
            throw new InvalidArgumentException('Amount is too large.');
        }

        return ($m[1] ?? '') === '-' ? -$centavos : $centavos;
    }

    /** Centavos to the DECIMAL string MySQL stores, e.g. 123450 -> "1234.50". */
    public static function toDecimal(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';
        $abs = abs($centavos);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Display form, e.g. ₱1,234.50 */
    public static function format(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';
        $abs = abs($centavos);

        return $sign . '₱' . number_format(intdiv($abs, 100)) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** DECIMAL column value (string) to centavos. */
    public static function fromColumn(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return self::parse(is_string($value) ? $value : (string)$value);
    }
}
