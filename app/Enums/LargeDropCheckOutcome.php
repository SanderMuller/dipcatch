<?php declare(strict_types=1);

namespace App\Enums;

/** What the second reading of a large drop said. */
enum LargeDropCheckOutcome: string
{
    /** The shop still charged the low price, so the drop alerted. */
    case Confirmed = 'confirmed';

    /** The shop read something else, so the drop was a wrong price and nothing alerted. */
    case Rejected = 'rejected';
}
