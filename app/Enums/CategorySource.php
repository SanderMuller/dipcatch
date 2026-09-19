<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Who placed a product in its category. A user's choice, a cleared one
 * included, is final; automatic categorisation only writes where this is
 * still null.
 */
enum CategorySource: string
{
    case User = 'user';
    case Auto = 'auto';
}
