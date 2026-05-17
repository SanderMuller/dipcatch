<?php declare(strict_types=1);

namespace App\Enums;

enum ShopHealth: string
{
    case Ok = 'ok';
    case Failing = 'failing';
    case Dead = 'dead';
}
