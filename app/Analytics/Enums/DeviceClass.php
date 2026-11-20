<?php declare(strict_types=1);

namespace App\Analytics\Enums;

enum DeviceClass: string
{
    case Mobile = 'mobile';
    case Tablet = 'tablet';
    case Desktop = 'desktop';
}
