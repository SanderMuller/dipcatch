<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Where a shop's comparable pack size came from.
 *
 * Only {@see self::Stated} and {@see self::Confirmed} may win the per-unit
 * ranking. An inferred size is shown with a marker and kept out of ranking and
 * alerting: agreement among the shops that state a size says what *those*
 * shops sell, and says nothing about the silent one. A confirmed size is the
 * agreed size Jev judged the silent page to sell, from its title and price.
 */
enum PackProvenance: string
{
    case Stated = 'stated';
    case Inferred = 'inferred';
    case Confirmed = 'confirmed';
}
