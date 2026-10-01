<?php declare(strict_types=1);

namespace App\Enums;

enum DepthSource: string
{
    case Jev = 'jev';
    case Category = 'category';
    case PromotionNow = 'promotion_now';
    case None = 'none';
}
