<?php declare(strict_types=1);

namespace App\Support;

final class Config
{
    public static function int(string $key, int $default): int
    {
        $value = config($key);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = config($key);

        return is_string($value) ? $value : $default;
    }
}
