<?php declare(strict_types=1);

namespace App\Enums;

/** What the shop's next successful reading said about a large drop. */
enum LargeDropCheckOutcome: string
{
    /** The shop still charged the low price, or less. */
    case Confirmed = 'confirmed';

    /** The shop charged more again, or the offer was out of stock. */
    case Rejected = 'rejected';
}
