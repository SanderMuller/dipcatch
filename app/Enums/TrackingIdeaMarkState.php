<?php declare(strict_types=1);

namespace App\Enums;

enum TrackingIdeaMarkState: string
{
    /** Bought somewhere DipCatch does not see, or tracked under another name. */
    case Done = 'done';
    /** Not something this person buys. */
    case Skipped = 'skipped';
}
