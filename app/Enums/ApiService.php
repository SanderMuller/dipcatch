<?php declare(strict_types=1);

namespace App\Enums;

/** An outside API whose calls the admin dashboard counts. */
enum ApiService: string
{
    case TypeSafe = 'typesafe';
    case Serper = 'serper';
    case Bol = 'bol';

    public function label(): string
    {
        return match ($this) {
            self::TypeSafe => 'Jev (TypeSafe)',
            self::Serper => 'Serper',
            self::Bol => 'bol.com API',
        };
    }
}
