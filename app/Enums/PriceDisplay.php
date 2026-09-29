<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Which price a product leads with, when the person chose one. Without a
 * choice the pack price leads until the shops sell different pack sizes.
 */
enum PriceDisplay: string
{
    case Pack = 'pack';
    case Unit = 'unit';
}
