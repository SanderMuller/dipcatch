<?php declare(strict_types=1);

namespace App\Support;

final class MoneyFormatter
{
    public static function format(?string $amount, string $currency): string
    {
        if ($amount === null) {
            return '—';
        }

        return $currency . ' ' . number_format((float) $amount, 2, '.', ',');
    }
}
