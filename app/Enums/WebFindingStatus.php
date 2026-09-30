<?php declare(strict_types=1);

namespace App\Enums;

/** How far the checks on one web finding got. See specs/web-shop-discovery.md §3.4. */
enum WebFindingStatus: string
{
    /** Stored from the search; the first check has not answered yet. */
    case New = 'new';

    case Rejected = 'rejected';

    /** Passed the first check; the page is still to be read. */
    case PendingRead = 'pending_read';

    /** The page was read; the second check has not answered yet. */
    case Read = 'read';

    /** Claimed by a running second check. */
    case Checking = 'checking';

    case Unreadable = 'unreadable';

    case Proposed = 'proposed';

    case Declined = 'declined';

    /**
     * @return list<self>
     */
    public static function unfinished(): array
    {
        return [self::New, self::PendingRead, self::Read, self::Checking];
    }
}
