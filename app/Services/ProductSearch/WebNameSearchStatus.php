<?php declare(strict_types=1);

namespace App\Services\ProductSearch;

/** How a "Search the web" request for a typed name ended. Only `Found` carries results. */
enum WebNameSearchStatus: string
{
    case Found = 'found';

    /** Shorter than three characters once the search operators are stripped. */
    case TooShort = 'too_short';

    /** The gate blocked it; see {@see NameSearchVerdict}. */
    case TooVague = 'too_vague';

    case MatchesEveryWord = 'matches_every_word';

    /** The account used today's web searches. */
    case CapSpent = 'cap_spent';

    /** A search for this text by this account is already running. */
    case Busy = 'busy';

    /** The provider failed, the app-wide cap is spent, or the save failed. */
    case Failed = 'failed';
}
