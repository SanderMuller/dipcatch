<?php declare(strict_types=1);

namespace App\Enums;

enum ScrapeStatus: string
{
    case Ok = 'ok';
    case EmptyMatch = 'empty_match';
    case HttpError = 'http_error';
    case ParseError = 'parse_error';
    case CurrencyMismatch = 'currency_mismatch';

    /**
     * Do not write this. The pre-adapter `HtmlScraper` stored it through
     * `RecordScrape`, so old rows can still hold it and the cast needs the
     * case. A robots refusal today is {@see self::RobotsDisallowed}.
     */
    case RobotsBlocked = 'robots_blocked';

    case NeedsJs = 'needs_js';

    case Pending = 'pending';
    case Blocked = 'blocked';
    case RateLimited = 'rate_limited';
    case TransientServerError = '5xx';
    case RobotsDisallowed = 'robots_disallowed';
}
