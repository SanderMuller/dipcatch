<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Where a shop's comparable pack size came from.
 *
 * Only {@see self::Stated} may win the per-unit ranking. An inferred size is
 * shown with a marker and kept out of ranking and alerting: agreement among the
 * shops that state a size says what *those* shops sell, and says nothing about
 * the silent one.
 */
enum PackProvenance: string
{
    case Stated = 'stated';
    case Inferred = 'inferred';
}
