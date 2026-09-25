<?php declare(strict_types=1);

namespace App\Services\Drops;

/**
 * What one reading means for a large drop, once the drop itself is known to
 * be large. The detector acts on it and the product page reports it, so the
 * two cannot disagree about whether a second reading is on its way.
 */
enum LargeDropVerdict
{
    /** Not the reading this drop is about: ineligible, a joining shop, another shop, or another price. */
    case NotThisReading;
    /** A dataset or API shop, which alerts on one reading. */
    case Exempt;
    /** The shop's previous reading agreed. */
    case Confirmed;
    /** A second reading is needed. */
    case Awaiting;
}
